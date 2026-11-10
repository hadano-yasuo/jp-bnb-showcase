<?php

namespace App\Http\Controllers;

use App\Jobs\VerifyGuestIdImageJob;
use App\Models\GuestRegistration;
use App\Models\GuestRegistrationToken;
use App\Services\GuestIdImage\GuestIdImageStore;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * ゲストが自分の情報を登録するための公開フォーム。
 *
 * URL: /register/{token}
 * トークンは予約作成時（SyncBookingsJob 内）に自動発行され、有効期限は
 * booking.departure + 7 日。管理者が Filament から手動延長可能。
 *
 * 記載事項は台東区旅館業のてびき（令和7年4月版）14 ページに準拠。
 * ?edit=<guest_number> で既存レコードの編集モード。
 */
class GuestRegistrationController extends Controller
{
    /** 対応言語（メッセージ翻訳パイプラインと同じセット、'other' は含めない）。 */
    public const SUPPORTED_LOCALES = ['ja', 'en', 'zh', 'ko', 'es', 'fr', 'de', 'pt', 'th', 'vi'];

    public function show(Request $request, string $token): View
    {
        $registrationToken = $this->resolveToken($token);
        $booking = $registrationToken->booking->load('listing');

        $registrations = $booking->guestRegistrations()->get();
        $hasRepresentative = $registrations->contains(fn (GuestRegistration $r): bool => $r->is_representative);
        $representativePhone = optional(
            $registrations->firstWhere('is_representative', true)
        )->phone;

        // ?edit=N が指定されていれば該当レコードを編集モードで表示
        $editingRegistration = $this->resolveEditing($request, $registrations);

        return view('guest-registrations.show', [
            'token' => $registrationToken,
            'booking' => $booking,
            'registrations' => $registrations,
            'nextGuestNumber' => $registrations->max('guest_number') + 1,
            'copyOptions' => $this->buildCopyOptions($registrations),
            'hasRepresentative' => $hasRepresentative,
            'representativePhone' => $representativePhone,
            'locale' => $this->resolveLocale($request),
            'editingRegistration' => $editingRegistration,
        ]);
    }

    public function store(Request $request, string $token, GuestIdImageStore $imageStore): RedirectResponse
    {
        $registrationToken = $this->resolveToken($token);
        $booking = $registrationToken->booking;
        $locale = $this->resolveLocale($request);

        // Laravel の validation メッセージを送信者のロケールで返すため、
        // ロケールをセット。laravel-lang/lang の翻訳を用いる。
        App::setLocale($this->laravelLocale($locale));

        // フィールド名（:attribute）も送信者のロケールに寄せる。
        // ゲスト向けフォーム表示に使っているラベルを流用する。
        $translations = require resource_path('views/guest-registrations/translations.php');
        $t = $translations[$locale] ?? $translations['ja'];
        $attributes = [
            'name' => $t['name'],
            'address' => $t['address'],
            'age' => $t['age'],
            'occupation' => $t['occupation'],
            'sex' => $t['sex'],
            'nationality' => $t['nationality'],
            'nationality_type' => $t['nationality'],
            'previous_stay' => $t['previous_stay'],
            'next_destination' => $t['next_destination'],
            'phone' => $t['phone'],
            'arrival_at' => $t['arrival_at'],
            'departure_at' => $t['departure_at'],
            'id_image' => $t['id_image'],
        ];

        $editing = $this->resolveEditing($request, $booking->guestRegistrations()->get());

        // まず nationality_type を先に解決して国籍を組み立てる（後段の身分証ルール判定で使う）。
        $nationalityType = $request->input('nationality_type');
        $nationality = $nationalityType === 'japan'
            ? '日本'
            : (string) $request->input('nationality', '');
        $isJapanese = $nationalityType === 'japan';
        $isRepresentative = $request->boolean('is_representative');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string', 'max:500'],
            'age' => ['required', 'integer', 'min:0', 'max:120'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'sex' => ['required', 'in:male,female,other,prefer_not_to_say'],
            'nationality_type' => ['required', 'in:japan,other'],
            'nationality' => [$nationalityType === 'other' ? 'required' : 'nullable', 'string', 'max:100'],
            'previous_stay' => ['required', 'string', 'max:255'],
            'next_destination' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:64'],
            'arrival_at' => [$isRepresentative ? 'required' : 'nullable', 'date'],
            'departure_at' => [$isRepresentative ? 'required' : 'nullable', 'date', 'after:arrival_at'],
            'id_image' => ['nullable', 'image', 'max:20480'], // 20MB
            'privacy_consent' => ['required', 'accepted'],
        ];

        $consentMessages = [
            'ja' => '同意にチェックを入れてください',
            'en' => 'Please tick the consent box.',
            'zh' => '请勾选同意框。',
            'ko' => '동의란에 체크해 주세요.',
            'es' => 'Marque la casilla de consentimiento.',
            'fr' => 'Merci de cocher la case de consentement.',
            'de' => 'Bitte kreuzen Sie das Einwilligungsfeld an.',
            'pt' => 'Assinale a caixa de consentimento.',
            'th' => 'กรุณาทำเครื่องหมายในช่องยินยอม',
            'vi' => 'Vui lòng đánh dấu vào ô đồng ý.',
        ];

        $imageNotImageMessages = [
            'ja' => '身分証画像は JPEG / PNG / WebP 形式で提出してください。',
            'en' => 'ID image must be JPEG, PNG, or WebP.',
            'zh' => '身份证件图片请使用 JPEG / PNG / WebP 格式。',
            'ko' => '신분증 이미지는 JPEG / PNG / WebP 형식으로 제출해 주세요.',
            'es' => 'La imagen debe estar en formato JPEG, PNG o WebP.',
            'fr' => 'La pièce d\'identité doit être au format JPEG, PNG ou WebP.',
            'de' => 'Das Ausweisbild muss im JPEG-, PNG- oder WebP-Format vorliegen.',
            'pt' => 'A imagem do documento deve estar em formato JPEG, PNG ou WebP.',
            'th' => 'ภาพบัตรประจำตัวต้องเป็นรูปแบบ JPEG / PNG / WebP',
            'vi' => 'Ảnh giấy tờ tùy thân phải ở định dạng JPEG, PNG hoặc WebP.',
        ];

        $imageMaxMessages = [
            'ja' => '身分証画像のサイズは 20MB 以下にしてください。',
            'en' => 'ID image must not exceed 20MB.',
            'zh' => '身份证件图片不得超过 20MB。',
            'ko' => '신분증 이미지는 20MB 이하여야 합니다.',
            'es' => 'La imagen no puede superar los 20MB.',
            'fr' => 'L\'image ne doit pas dépasser 20 Mo.',
            'de' => 'Das Ausweisbild darf 20 MB nicht überschreiten.',
            'pt' => 'A imagem não pode exceder 20 MB.',
            'th' => 'ภาพบัตรประจำตัวต้องมีขนาดไม่เกิน 20MB',
            'vi' => 'Ảnh giấy tờ tùy thân không được vượt quá 20MB.',
        ];

        $imageUploadedMessages = [
            'ja' => '画像のアップロードに失敗しました。ファイルサイズが大きすぎるか、通信が切断された可能性があります。iPhone の HEIC 形式はご利用の写真アプリで JPEG に変換してから再度お試しください。',
            'en' => 'Image upload failed. The file may be too large or the connection was interrupted. iPhone HEIC files must be converted to JPEG in the Photos app first.',
            'zh' => '图片上传失败。文件可能过大，或网络中断。iPhone 的 HEIC 格式请在照片 App 中转换为 JPEG 后重试。',
            'ko' => '이미지 업로드에 실패했습니다. 파일이 너무 크거나 통신이 중단되었을 수 있습니다. iPhone HEIC 형식은 사진 앱에서 JPEG로 변환한 후 다시 시도해 주세요.',
            'es' => 'No se pudo subir la imagen. El archivo puede ser demasiado grande o la conexión se interrumpió. Convierta los HEIC del iPhone a JPEG en la app Fotos y vuelva a intentarlo.',
            'fr' => 'Échec du téléversement. Le fichier est peut-être trop volumineux ou la connexion a été interrompue. Convertissez les HEIC de l\'iPhone en JPEG dans l\'app Photos avant de réessayer.',
            'de' => 'Der Upload ist fehlgeschlagen. Die Datei ist möglicherweise zu groß oder die Verbindung wurde unterbrochen. iPhone-HEIC-Dateien vorher in der Fotos-App in JPEG umwandeln.',
            'pt' => 'Falha no carregamento da imagem. O ficheiro pode ser demasiado grande ou a ligação foi interrompida. Converta os HEIC do iPhone para JPEG na app Fotografias antes de tentar novamente.',
            'th' => 'อัปโหลดภาพไม่สำเร็จ ไฟล์อาจใหญ่เกินไปหรือการเชื่อมต่อขาด ไฟล์ HEIC จาก iPhone ต้องแปลงเป็น JPEG ในแอปรูปภาพก่อน',
            'vi' => 'Tải ảnh lên thất bại. Tệp có thể quá lớn hoặc kết nối bị gián đoạn. Tệp HEIC của iPhone cần chuyển sang JPEG trong ứng dụng Ảnh trước khi thử lại.',
        ];

        $validated = $request->validate($rules, [
            'privacy_consent.required' => $consentMessages[$locale] ?? $consentMessages['ja'],
            'privacy_consent.accepted' => $consentMessages[$locale] ?? $consentMessages['ja'],
            'id_image.image' => $imageNotImageMessages[$locale] ?? $imageNotImageMessages['ja'],
            'id_image.max' => $imageMaxMessages[$locale] ?? $imageMaxMessages['ja'],
            'id_image.uploaded' => $imageUploadedMessages[$locale] ?? $imageUploadedMessages['ja'],
        ], $attributes);

        // 代表者の重複を防止: 別ゲストが既に代表者ならエラー。編集で自分が既存代表者なら OK。
        if ($isRepresentative) {
            $existingRep = $booking->guestRegistrations()
                ->where('is_representative', true)
                ->when($editing !== null, fn ($q) => $q->where('id', '!=', $editing->id))
                ->first();
            if ($existingRep !== null) {
                $dupRepMessages = [
                    'ja' => '既に別の方が代表者として登録されています。代表者は 1 名のみ選択してください。',
                    'en' => 'Another guest is already registered as the representative. Only one representative can be selected.',
                    'zh' => '已有其他人登记为代表人。代表人只能选择一位。',
                    'ko' => '이미 다른 분이 대표자로 등록되어 있습니다. 대표자는 한 분만 선택해 주세요.',
                    'es' => 'Otro huésped ya está registrado como representante. Solo se puede seleccionar un representante.',
                    'fr' => 'Un autre voyageur est déjà enregistré comme représentant. Un seul représentant peut être sélectionné.',
                    'de' => 'Ein anderer Gast ist bereits als Vertreter registriert. Nur ein Vertreter kann ausgewählt werden.',
                    'pt' => 'Outro hóspede já está registado como representante. Apenas um representante pode ser selecionado.',
                    'th' => 'มีผู้อื่นลงทะเบียนเป็นผู้แทนอยู่แล้ว สามารถเลือกผู้แทนได้เพียงหนึ่งท่าน',
                    'vi' => 'Đã có khách khác được đăng ký làm đại diện. Chỉ có thể chọn một người đại diện.',
                ];

                return back()
                    ->withInput()
                    ->withErrors(['is_representative' => $dupRepMessages[$locale] ?? $dupRepMessages['ja']]);
            }
        }

        // 身分証ルール: 日本国籍かつ 18 歳未満のみ任意、他は必須。
        // 編集時は既存の画像があれば新規添付なしでも OK。
        $isMinor = (int) $validated['age'] < 18;
        $idRequired = ! ($isJapanese && $isMinor);
        $hasExistingImage = $editing !== null && $editing->id_image_path !== null;
        if ($idRequired && ! $request->hasFile('id_image') && ! $hasExistingImage) {
            $messages = [
                'ja' => '身分証明書の画像を添付してください（日本国籍かつ 18 歳未満の方を除く）',
                'en' => 'Please attach an ID image (not required for Japanese nationals under 18).',
                'zh' => '请附上身份证件图片（日本国籍且未满 18 岁者除外）。',
                'ko' => '신분증 이미지를 첨부해 주세요(일본 국적이며 18세 미만인 경우 제외).',
                'es' => 'Adjunte una imagen de identificación (no requerido para japoneses menores de 18).',
                'fr' => 'Joignez une photo de votre pièce d\'identité (non requise pour les ressortissants japonais de moins de 18 ans).',
                'de' => 'Bitte fügen Sie ein Ausweisbild bei (nicht erforderlich für japanische Staatsangehörige unter 18).',
                'pt' => 'Anexe uma imagem do documento de identificação (não obrigatório para japoneses com menos de 18 anos).',
                'th' => 'กรุณาแนบภาพบัตรประจำตัว (ไม่จำเป็นสำหรับสัญชาติญี่ปุ่นและอายุต่ำกว่า 18)',
                'vi' => 'Vui lòng đính kèm ảnh giấy tờ tùy thân (không bắt buộc với công dân Nhật dưới 18 tuổi).',
            ];

            return back()
                ->withInput()
                ->withErrors(['id_image' => $messages[$locale] ?? $messages['ja']]);
        }

        // 編集時: 既存の guest_number を維持。新規時: 次の番号を採番。
        $guestNumber = $editing !== null
            ? $editing->guest_number
            : (((int) $booking->guestRegistrations()->max('guest_number')) + 1);

        $imagePath = $editing?->id_image_path;
        $imageSha256 = $editing?->id_image_sha256;
        $imageReplaced = false;
        if ($request->hasFile('id_image')) {
            $stored = $imageStore->storeOriginal(
                $request->file('id_image'),
                $booking->id,
                $guestNumber,
            );
            $imagePath = $stored['path'];
            $imageSha256 = $stored['sha256'];
            $imageReplaced = true;
        }

        $payload = [
            'booking_id' => $booking->id,
            'guest_number' => $guestNumber,
            'name' => $validated['name'],
            'address' => $validated['address'],
            'age' => $validated['age'],
            'occupation' => $validated['occupation'] ?? null,
            'nationality' => $nationality,
            'sex' => $validated['sex'],
            'is_representative' => $isRepresentative,
            'arrival_at' => $isRepresentative ? ($validated['arrival_at'] ?? null) : null,
            'departure_at' => $isRepresentative ? ($validated['departure_at'] ?? null) : null,
            'phone' => $validated['phone'],
            'previous_stay' => $validated['previous_stay'],
            'next_destination' => $validated['next_destination'],
            'id_image_path' => $imagePath,
            'id_image_sha256' => $imageSha256,
            'submitter_ip' => $request->ip(),
            'submitter_user_agent' => substr((string) $request->userAgent(), 0, 512),
            'submitted_at' => now(),
        ];

        // 画像が新規/差し替えされた場合は AI 判定と管理者判定をリセットして再検証を発火。
        if ($imageReplaced) {
            $payload['id_image_compressed'] = false;
            $payload['id_image_verdict'] = GuestRegistration::VERDICT_PENDING;
            $payload['id_image_ai_notes'] = null;
            $payload['id_image_ai_extracted'] = null;
            $payload['id_image_verified_at'] = null;
            $payload['admin_verdict'] = null;
            $payload['admin_verdict_reason'] = null;
            $payload['admin_reviewed_at'] = null;
            $payload['admin_reviewed_by'] = null;
        }

        $registration = DB::transaction(function () use ($editing, $payload): GuestRegistration {
            if ($editing !== null) {
                $editing->update($payload);

                return $editing->fresh();
            }

            return GuestRegistration::create($payload);
        });

        // 画像が新規添付または差し替えられた場合のみ AI 再検証。
        if ($imageReplaced && $registration->id_image_path !== null) {
            VerifyGuestIdImageJob::dispatch($registration->id);
        }

        return redirect()
            ->route('guest-registrations.show', ['token' => $registrationToken->token, 'lang' => $locale])
            ->with('status', $editing !== null ? 'updated' : 'submitted');
    }

    /**
     * ?edit=<guest_number> をパースして対応する GuestRegistration を返す。
     * 該当なしなら null（= 新規モード）。
     *
     * @param  Collection<int, GuestRegistration>  $registrations
     */
    private function resolveEditing(Request $request, $registrations): ?GuestRegistration
    {
        $editParam = $request->input('edit');
        if ($editParam === null || $editParam === '') {
            return null;
        }

        $guestNumber = (int) $editParam;
        if ($guestNumber < 1) {
            return null;
        }

        return $registrations->firstWhere('guest_number', $guestNumber);
    }

    /**
     * 内部ロケール（zh）を Laravel の validation.php が期待するコード（zh_CN）に
     * マッピング。他は同一。
     */
    private function laravelLocale(string $locale): string
    {
        return match ($locale) {
            'zh' => 'zh_CN',
            default => $locale,
        };
    }

    /**
     * ?lang= を最優先、なければ Accept-Language、それも無ければ ja。
     */
    private function resolveLocale(Request $request): string
    {
        $requested = (string) $request->query('lang', '');
        if (in_array($requested, self::SUPPORTED_LOCALES, true)) {
            return $requested;
        }

        $preferred = $request->getPreferredLanguage(self::SUPPORTED_LOCALES);

        return in_array($preferred, self::SUPPORTED_LOCALES, true) ? $preferred : 'ja';
    }

    private function resolveToken(string $token): GuestRegistrationToken
    {
        $registrationToken = GuestRegistrationToken::query()
            ->with(['booking.guestRegistrations'])
            ->where('token', $token)
            ->first();

        if ($registrationToken === null) {
            throw new NotFoundHttpException;
        }

        if ($registrationToken->isExpired()) {
            abort(410, 'このフォームの有効期限は切れています / This form has expired.');
        }

        return $registrationToken;
    }

    /**
     * 「他のデータからコピー」用の候補を構築する。
     * 名前・年齢は毎回入力させたいので除外し、住所や国籍などの共通しやすい項目のみ返す。
     *
     * @param  Collection<int, GuestRegistration>  $registrations
     * @return list<array{guest_number: int, label: string, address: string, occupation: string|null, nationality: string, previous_stay: string|null, next_destination: string|null}>
     */
    private function buildCopyOptions($registrations): array
    {
        return $registrations->map(function (GuestRegistration $r): array {
            return [
                'guest_number' => $r->guest_number,
                'label' => sprintf('#%d %s', $r->guest_number, $r->name),
                'address' => $r->address,
                'occupation' => $r->occupation,
                'nationality' => $r->nationality,
                'previous_stay' => $r->previous_stay,
                'next_destination' => $r->next_destination,
            ];
        })->values()->all();
    }
}
