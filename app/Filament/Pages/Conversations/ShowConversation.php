<?php

namespace App\Filament\Pages\Conversations;

use App\Jobs\Beds24\SyncMessagesJob;
use App\Mail\AutoMessageMail;
use App\Mail\GuestRegistrationLinkMail;
use App\Models\AutoMessageRule;
use App\Models\Booking;
use App\Models\LearningSignal;
use App\Models\Listing;
use App\Models\Message;
use App\Models\MessageDraft;
use App\Models\MessageTemplate;
use App\Services\AI\LanguageDetector;
use App\Services\AI\ProofreadService;
use App\Services\AI\ReplyDrafter;
use App\Services\AI\TranslationService;
use App\Services\AutoMessage\BodyRenderer;
use App\Services\Beds24\Exceptions\Beds24Exception;
use App\Services\Beds24\MessageReadSyncer;
use App\Services\Beds24\MessageSender;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Computed;
use Throwable;

/**
 * 予約単位の会話スレッド。/admin/conversations/{bookingId}。
 *
 * LINE 風 UI:
 * - guest = 左、host = 右、system = 中央（軽め）
 * - 時系列昇順（古い→新しい）
 * - Alpine.js で開いた瞬間に scroll bottom
 * - 開いた時に guest 未読を MessageReadSyncer で既読化
 *
 * 返信フォーム統合:
 * - デフォルト: body textarea + 送信ボタン
 * - 「AI支援」ボタンで prompt / 生成 / 言語 / 履歴 パネル展開
 * - 返信の source message は「その予約の最新 guest メッセージ」を使う
 *   （継承する booking_id / room_id / property_id が必要なため）
 *
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $latestGuestMessage
 * @property-read Listing|null $listing
 * @property-read Booking|null $booking
 * @property-read Collection<int, MessageDraft> $drafts
 * @property-read Collection<int, MessageTemplate> $templates
 */
class ShowConversation extends Page
{
    protected string $view = 'filament.pages.conversations.show';

    protected static ?string $slug = 'conversations/{bookingId}';

    protected static bool $shouldRegisterNavigation = false;

    public int $bookingId;

    /**
     * ホストが入力する日本語本文。ゲストが日本語話者ならそのまま送信、
     * それ以外はプレビュー時に translatedBody へ翻訳される。
     */
    public string $body = '';

    /**
     * 「翻訳プレビュー」実行後の送信予定テキスト（ゲスト言語）。
     * body が変更されるとリセット。ja の会話では body と同じ。
     */
    public string $translatedBody = '';

    public string $prompt = '';

    public string $language = 'ja';

    public ?string $languageLabel = null;

    public bool $generating = false;

    public bool $translating = false;

    /**
     * 送信直前の誤字脱字チェックで検出した指摘。空なら未検出/未実行。
     *
     * @var list<array{type: string, quote: string, reason: string}>
     */
    public array $proofreadIssues = [];

    /**
     * 誤字脱字チェックが提示した修正案の全文。
     */
    public string $proofreadSuggestion = '';

    // === メール送信モーダル (Task #5: URL 剥奪 OTA 対策) ==========================
    // Booking.com 経由 Agoda 予約等では beds24 messenger 内の URL が剥奪される。
    // ホストが任意メアドへ直接メールで登録リンクを送るためのモーダル。
    // beds24 chat には投稿せず、履歴には system 行で「📧 メール送信 → xxx」を残す。

    public bool $emailModalOpen = false;

    public string $emailTo = '';

    public string $emailSubject = '';

    public string $emailBody = '';

    public bool $emailIncludeNoReplyFooter = true;

    public function mount(int $bookingId): void
    {
        $this->bookingId = $bookingId;

        abort_if($this->messages->isEmpty(), 404);

        $this->markGuestAsRead();
        $this->initLanguage();
    }

    public function getTitle(): string
    {
        return "予約 {$this->bookingId}";
    }

    /**
     * 「更新」ボタンから呼ばれる: beds24 から最新メッセージを同期取得。
     */
    public function syncMessages(): void
    {
        try {
            dispatch_sync(new SyncMessagesJob);
            unset($this->messages);
            Notification::make()->title('最新メッセージを取得しました')->success()->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('同期に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function getHeading(): string
    {
        return "予約 #{$this->bookingId}";
    }

    /**
     * その guest メッセージを「未読」として表示すべきか。
     * ルール: is_read=false かつ、その後に host メッセージが存在しない場合のみ true。
     * （既に host が返信済みなら、それより前の guest メッセージは実質既読とみなす）
     */
    public function shouldShowUnread(Message $msg): bool
    {
        if ($msg->source !== Message::SOURCE_GUEST || $msg->is_read) {
            return false;
        }

        // この guest メッセージ以降に host メッセージがあれば未読表示しない
        // sent_at は Message モデルで非 null 保証（beds24 同期時に必ず入る）。
        $laterHost = $this->messages->first(
            fn (Message $m) => $m->source === Message::SOURCE_HOST
                && $m->sent_at->greaterThan($msg->sent_at)
        );

        return $laterHost === null;
    }

    /**
     * 会話状態: 予約の最新メッセージが guest かつ返信不要フラグなしなら「未返信」。
     */
    public function isUnreplied(): bool
    {
        $last = $this->messages->last();

        return $last !== null
            && $last->source === Message::SOURCE_GUEST
            && ! $last->no_reply_needed;
    }

    /**
     * 会話状態: 予約の最新メッセージが guest かつ返信不要フラグ有りなら「返信不要」。
     */
    public function isMarkedNoReplyNeeded(): bool
    {
        $last = $this->messages->last();

        return $last !== null
            && $last->source === Message::SOURCE_GUEST
            && $last->no_reply_needed;
    }

    /**
     * 最新の guest メッセージに no_reply_needed=true を立てる。
     */
    public function markNoReplyNeeded(): void
    {
        $last = $this->messages->last();
        if ($last === null || $last->source !== Message::SOURCE_GUEST) {
            return;
        }
        $last->update(['no_reply_needed' => true]);
        unset($this->messages);
        Notification::make()->title('返信不要にしました')->success()->send();
    }

    /**
     * この予約への自動送信メッセージを ON/OFF するトグル。
     * 予約単位で auto_message_opt_out を反転する。ON にすると Dispatcher が
     * SKIP_OPTED_OUT で pending を落とす（実送信もされない）。
     */
    public function toggleAutoMessageOptOut(): void
    {
        $booking = $this->booking;
        if ($booking === null) {
            return;
        }

        $newValue = ! $booking->auto_message_opt_out;
        $booking->update(['auto_message_opt_out' => $newValue]);
        unset($this->booking);

        Notification::make()
            ->title($newValue ? '自動送信を停止しました' : '自動送信を再開しました')
            ->success()
            ->send();
    }

    /**
     * no_reply_needed を解除。
     */
    public function unmarkNoReplyNeeded(): void
    {
        $last = $this->messages->last();
        if ($last === null || $last->source !== Message::SOURCE_GUEST) {
            return;
        }
        $last->update(['no_reply_needed' => false]);
        unset($this->messages);
        Notification::make()->title('返信不要を取り消しました')->success()->send();
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            url('/conversations') => 'メッセージ',
        ];
    }

    /**
     * @return Collection<int, Message>
     */
    #[Computed]
    public function messages(): Collection
    {
        // 問い合わせ由来の自社予約でも、Beds24BookingCreator が GuestChannelMessage を
        // Message に移送しているため、ここで GuestChannelMessage を混ぜる必要はない。
        return Message::query()
            ->where('beds24_booking_id', $this->bookingId)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();
    }

    #[Computed]
    public function latestGuestMessage(): ?Message
    {
        /** @var Message|null $latest */
        $latest = $this->messages->where('source', Message::SOURCE_GUEST)->last();

        return $latest;
    }

    #[Computed]
    public function listing(): ?Listing
    {
        $first = $this->messages->first();
        if ($first === null) {
            return null;
        }

        return Listing::query()
            ->with('building')
            ->where('beds24_room_id', $first->beds24_room_id)
            ->first();
    }

    #[Computed]
    public function booking(): ?Booking
    {
        return Booking::query()
            ->where('beds24_booking_id', $this->bookingId)
            ->first();
    }

    /**
     * @return Collection<int, MessageDraft>
     */
    #[Computed]
    public function drafts(): Collection
    {
        /** @var Collection<int, MessageDraft> $drafts */
        $drafts = MessageDraft::query()
            ->whereIn('message_id', $this->messages->pluck('id')->all())
            ->orderByDesc('generated_at')
            ->get();

        return $drafts;
    }

    /**
     * 返信フォームで挿入できる定型文（有効かつ現リスティング対象、sort_order 昇順）。
     * 対象リスティング未指定のテンプレ（universal）は常に含む。
     *
     * @return Collection<int, MessageTemplate>
     */
    #[Computed]
    public function templates(): Collection
    {
        $listingId = $this->listing?->id;

        /** @var Collection<int, MessageTemplate> $templates */
        $templates = MessageTemplate::query()
            ->where('is_active', true)
            ->where(function ($q) use ($listingId) {
                // universal（listing 紐付けが1件もない）
                $q->whereDoesntHave('listings');
                // または現リスティングを含む
                if ($listingId !== null) {
                    $q->orWhereHas('listings', fn ($lq) => $lq->where('listings.id', $listingId));
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $templates;
    }

    /**
     * 返信フォームで挿入できる自動送信メッセージルール。定型文と同じ dropdown に
     * 並べて手動送信の下敷きに使えるようにする。listing scope は templates() と同じで、
     * 「listings 未紐付け（universal）」または「現 listing を含む」ルールが対象。
     * is_enabled は無視（自動送信オフのルールも本文は使える）。
     *
     * @return Collection<int, AutoMessageRule>
     */
    #[Computed]
    public function autoMessageRules(): Collection
    {
        $listingId = $this->listing?->id;

        /** @var Collection<int, AutoMessageRule> $rules */
        $rules = AutoMessageRule::query()
            ->where(function ($q) use ($listingId) {
                $q->whereDoesntHave('listings');
                if ($listingId !== null) {
                    $q->orWhereHas('listings', fn ($lq) => $lq->where('listings.id', $listingId));
                }
            })
            ->orderBy('id')
            ->get();

        return $rules;
    }

    /**
     * 定型文を返信本文にセット。既存の入力があれば末尾に追記、なければ置換。
     * ドロップダウンの select から dispatch される。
     *
     * 定型文中の Blade 変数（{{ $guest_first_name }} 等）は、当会話の booking
     * コンテキストで展開してから挿入する。予約が取得できない場合は生の本文
     * をそのまま挿入する（フォールバック）。
     */
    public function useTemplate(int $templateId): void
    {
        $template = MessageTemplate::query()->find($templateId);
        if ($template === null) {
            return;
        }

        $this->insertRenderedBody($template->body, "template#{$templateId}");
    }

    /**
     * 自動送信ルールの本文を返信フォームに挿入。挙動は useTemplate と同じ
     * （Blade 変数展開 → 末尾追記 or 置換）。
     */
    public function useAutoMessageRule(int $ruleId): void
    {
        $rule = AutoMessageRule::query()->find($ruleId);
        if ($rule === null) {
            return;
        }

        $this->insertRenderedBody($rule->body, "auto_message_rule#{$ruleId}");
    }

    /**
     * 定型文 / 自動送信ルール共通の本文挿入ロジック。$sourceLabel は render 失敗時のログ用。
     */
    private function insertRenderedBody(string $rawBody, string $sourceLabel): void
    {
        $body = $rawBody;
        $booking = $this->booking;
        if ($booking !== null) {
            try {
                $body = app(BodyRenderer::class)->render($body, $booking);
            } catch (Throwable $e) {
                Log::warning('template variable render failed', [
                    'source' => $sourceLabel,
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
                // 展開失敗時は生の本文を挿入して人間に気づかせる
            }
        }

        if (trim($this->body) === '') {
            $this->body = $body;
        } else {
            $this->body = rtrim($this->body)."\n\n".$body;
        }
        // body 変更で translatedBody を無効化（updatedBody hook と同じ挙動）
        $this->translatedBody = '';
    }

    protected function markGuestAsRead(): void
    {
        $unread = $this->messages->filter(
            fn (Message $m) => $m->source === Message::SOURCE_GUEST && ! $m->is_read
        );
        if ($unread->isNotEmpty()) {
            app(MessageReadSyncer::class)->markAsRead($unread);
        }
    }

    protected function initLanguage(): void
    {
        $latest = $this->latestGuestMessage;
        if ($latest === null) {
            $this->language = 'ja';

            return;
        }

        if ($latest->detected_language !== null) {
            $this->language = $latest->detected_language;
            $this->languageLabel = $latest->detected_language_label;

            return;
        }

        try {
            $detected = app(LanguageDetector::class)->detect($latest->refresh());
            $this->language = $detected['language'];
            $this->languageLabel = $detected['label'];
        } catch (Throwable $e) {
            Log::warning('language detection failed at conversation view', [
                'booking_id' => $this->bookingId,
                'error' => $e->getMessage(),
            ]);
            $this->language = 'ja';
        }
    }

    public function generateAuto(): void
    {
        $this->doGenerate(null);
    }

    public function generateWithPrompt(): void
    {
        if (trim($this->prompt) === '') {
            Notification::make()->title('プロンプトが空です')->warning()->send();

            return;
        }
        $this->doGenerate($this->prompt);
    }

    protected function doGenerate(?string $userPrompt): void
    {
        $latest = $this->latestGuestMessage;
        if ($latest === null) {
            Notification::make()->title('返信対象のゲストメッセージがありません')->warning()->send();

            return;
        }

        $this->generating = true;
        try {
            $previousDraft = trim($this->body) !== '' ? $this->body : null;

            // 本文は常に日本語で生成する（body = 日本語、translatedBody = ゲスト言語のメンタルモデルに合わせる）。
            $draft = app(ReplyDrafter::class)->generate(
                sourceMessage: $latest,
                userPrompt: $userPrompt,
                language: 'ja',
                languageLabel: null,
                existingDraft: $previousDraft,
                conversationHistory: $this->messages,
            );

            if ($previousDraft !== null) {
                LearningSignal::create([
                    'message_id' => $latest->id,
                    'draft_id' => $draft->id,
                    'action' => LearningSignal::ACTION_REGENERATED,
                ]);
            }

            $this->body = $draft->draft_text;
            $this->translatedBody = '';

            // 非JA会話なら翻訳プレビューも同時に埋めて手作業を減らす。
            $translationNote = null;
            if ($this->language !== 'ja') {
                try {
                    $this->translatedBody = app(TranslationService::class)->translateFromJa(
                        japaneseText: $this->body,
                        targetLangCode: $this->language,
                        targetLangLabel: $this->languageLabel,
                    );
                } catch (Throwable $e) {
                    Log::warning('auto translation after draft generation failed', [
                        'booking_id' => $this->bookingId,
                        'error' => $e->getMessage(),
                    ]);
                    $translationNote = '翻訳は失敗したので「翻訳プレビュー」を手動で押してください';
                }
            }

            Notification::make()
                ->title('下書きを生成しました')
                ->body(trim(($translationNote !== null ? $translationNote."\n" : '')."in {$draft->input_tokens} / out {$draft->output_tokens} tok · \${$draft->cost_usd}"))
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::warning('draft generation failed', [
                'booking_id' => $this->bookingId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('生成に失敗しました')->body($e->getMessage())->danger()->send();
        } finally {
            $this->generating = false;
        }
    }

    public function useDraft(int $draftId): void
    {
        $draft = MessageDraft::query()
            ->whereIn('message_id', $this->messages->pluck('id')->all())
            ->where('id', $draftId)
            ->firstOrFail();
        $this->body = $draft->draft_text;
    }

    public function clearBody(): void
    {
        $latest = $this->latestGuestMessage;
        if ($latest !== null && trim($this->body) !== '') {
            $latestDraft = $this->drafts->first();
            LearningSignal::create([
                'message_id' => $latest->id,
                'draft_id' => $latestDraft?->id,
                'action' => LearningSignal::ACTION_CLEARED,
                'final_text' => $this->body,
            ]);
        }
        $this->body = '';
        $this->translatedBody = '';
        $this->prompt = '';
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /**
     * body（日本語）を変更すると translatedBody を無効化してプレビュー再生成を促す。
     * 誤字脱字チェックの結果も無効化して次回送信時に再チェックさせる。
     */
    public function updatedBody(): void
    {
        $this->translatedBody = '';
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /**
     * 「翻訳プレビュー」ボタン。body（日本語）→ ゲスト言語に翻訳して translatedBody にセット。
     * ja の会話では翻訳不要なのでコピーのみ。
     */
    public function translatePreview(): void
    {
        if (trim($this->body) === '') {
            Notification::make()->title('本文が空です')->warning()->send();

            return;
        }

        if ($this->language === 'ja') {
            $this->translatedBody = $this->body;
            Notification::make()->title('ゲストは日本語話者のためそのまま送信できます')->success()->send();

            return;
        }

        $this->translating = true;
        try {
            $this->translatedBody = app(TranslationService::class)->translateFromJa(
                japaneseText: $this->body,
                targetLangCode: $this->language,
                targetLangLabel: $this->languageLabel,
            );

            Notification::make()->title('翻訳しました')->body('内容を確認して送信してください')->success()->send();
        } catch (Throwable $e) {
            Log::warning('reply translation failed', [
                'booking_id' => $this->bookingId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('翻訳に失敗しました')->body($e->getMessage())->danger()->send();
        } finally {
            $this->translating = false;
        }
    }

    /**
     * 送信ボタン。まず Haiku で日本語本文の誤字脱字チェックを掛け、
     * 指摘があれば $proofreadIssues にセットしてパネル表示、送信は保留する。
     * 指摘なしなら直接 doSend() へ。
     */
    public function send(): void
    {
        if (trim($this->body) === '') {
            Notification::make()->title('本文が空です')->warning()->send();

            return;
        }

        try {
            $result = app(ProofreadService::class)->check($this->body);
        } catch (Throwable $e) {
            Log::warning('proofread service call failed unexpectedly', [
                'booking_id' => $this->bookingId,
                'error' => $e->getMessage(),
            ]);
            // fail-open: そのまま送信フローへ
            $this->doSend();

            return;
        }

        if ($result['ok'] === true) {
            $this->doSend();

            return;
        }

        $this->proofreadIssues = $result['issues'];
        $this->proofreadSuggestion = $result['suggestion'];

        Notification::make()
            ->title('誤字脱字の可能性を検出')
            ->body('内容を確認して修正案を採用するか、そのまま送信してください')
            ->warning()
            ->send();
    }

    /**
     * 誤字脱字チェックの警告を無視して送信する。
     */
    public function sendAnyway(): void
    {
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
        $this->doSend();
    }

    /**
     * 誤字脱字チェックの提案文を本文に反映する（送信はまだしない）。
     * 反映後の再チェックはユーザーが再度「送信」を押した時に自動で走る。
     */
    public function applyProofreadSuggestion(): void
    {
        if ($this->proofreadSuggestion !== '') {
            $this->body = $this->proofreadSuggestion;
            $this->translatedBody = '';
        }
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /**
     * 誤字脱字パネルを閉じる（本文はそのまま）。
     */
    public function dismissProofread(): void
    {
        $this->proofreadIssues = [];
        $this->proofreadSuggestion = '';
    }

    /**
     * 実際に beds24 へ送信する本体。send() / sendAnyway() から呼ばれる。
     */
    protected function doSend(): void
    {
        $latest = $this->latestGuestMessage;

        // ja でない会話は翻訳プレビュー必須
        if ($this->language !== 'ja' && trim($this->translatedBody) === '') {
            Notification::make()
                ->title('先に「翻訳プレビュー」を実行してください')
                ->body('日本語入力を確認できるようにゲスト言語で送ります')
                ->warning()
                ->send();

            return;
        }

        $textToSend = $this->language === 'ja' ? $this->body : $this->translatedBody;
        // 送信テキストと元の日本語入力（両方保存）
        $japaneseOriginal = $this->body;

        try {
            // 自社サイト直接予約 (direct) はメール配信、OTA は beds24 messenger 配信。
            // OTA でゲストメッセージがある場合は返信 (replyTo)、無い場合は booking に直接送信 (sendToBooking)。
            $booking = $this->booking;
            if ($booking !== null && $booking->isDirectBooking()) {
                $sent = $this->sendEmailMessageToDirectGuest($booking, $textToSend);
            } elseif ($latest !== null) {
                $sent = app(MessageSender::class)->replyTo($latest, $textToSend);
            } elseif ($booking !== null) {
                $sent = app(MessageSender::class)->sendToBooking($booking, $textToSend);
            } else {
                Notification::make()->title('予約情報が取得できません')->warning()->send();

                return;
            }

            // ja でない場合、送信内容と別に元の日本語を message_ja に保存して詳細で併記できるようにする
            if ($this->language !== 'ja') {
                $sent->update(['message_ja' => $japaneseOriginal]);
            }

            // LearningSignal は返信元メッセージがある時だけ記録 (無い場合の初回手動送信は学習対象外)
            if ($latest !== null) {
                $latestDraft = $this->drafts->first();
                LearningSignal::create([
                    'message_id' => $latest->id,
                    'draft_id' => $latestDraft?->id,
                    'action' => LearningSignal::ACTION_SENT,
                    'final_text' => $japaneseOriginal,
                    'edit_diff' => $latestDraft !== null && $latestDraft->draft_text !== $japaneseOriginal
                        ? "BEFORE:\n{$latestDraft->draft_text}\n\nAFTER:\n{$japaneseOriginal}"
                        : null,
                ]);
            }

            Notification::make()->title('返信を送信しました')->success()->send();

            $this->body = '';
            $this->translatedBody = '';
            $this->prompt = '';
            $this->proofreadIssues = [];
            $this->proofreadSuggestion = '';
            unset($this->messages, $this->drafts, $this->latestGuestMessage);
            $this->dispatch('conversation-updated');
        } catch (Beds24Exception $e) {
            Log::warning('reply send failed', [
                'booking_id' => $this->bookingId,
                'error' => $e->getMessage(),
                'context' => $e->context,
            ]);
            Notification::make()->title('送信に失敗しました')->body($e->getMessage())->danger()->send();
        }
    }

    /**
     * 自社サイト直接予約のゲスト宛にメールで本文を送り、同じ内容を Message として
     * 残す (admin のタイムラインに表示するため)。beds24 messaging は使わない。
     * 送信失敗時は Beds24Exception に包んで doSend 側の catch で通知する。
     */
    private function sendEmailMessageToDirectGuest(Booking $booking, string $textToSend): Message
    {
        $toEmail = trim((string) ($booking->email ?? ''));
        if ($toEmail === '') {
            throw new Beds24Exception(message: 'ゲストのメールアドレスが登録されていません');
        }

        try {
            Mail::to($toEmail)->send(new AutoMessageMail(
                subjectLine: $this->emailSubjectForHostReply($this->language),
                bodyRendered: $textToSend,
                guestLocale: $this->language,
            ));
        } catch (Throwable $e) {
            throw new Beds24Exception(
                message: 'メール送信に失敗しました: '.$e->getMessage(),
                context: ['booking_id' => $booking->beds24_booking_id, 'to' => $toEmail],
            );
        }

        return Message::create([
            'beds24_message_id' => null,
            'beds24_booking_id' => $booking->beds24_booking_id,
            'beds24_property_id' => $booking->beds24_property_id,
            'beds24_room_id' => $booking->beds24_room_id,
            'source' => Message::SOURCE_HOST,
            'message' => $textToSend,
            'is_read' => true,
            'sent_at' => now(),
            'fetched_at' => now(),
            'emailed_to' => $toEmail,
            'emailed_at' => now(),
            'raw_payload' => [],
        ]);
    }

    private function emailSubjectForHostReply(string $locale): string
    {
        $brand = 'Sample Stay';

        return match ($locale) {
            'en' => "A message from {$brand}",
            'es' => "Un mensaje de {$brand}",
            'zh' => "来自{$brand}的消息",
            'ko' => "{$brand} 안내",
            default => "{$brand} からのご連絡",
        };
    }

    // === メール送信 (URL 剥奪 OTA 対策) =====================================

    /**
     * 「メールで送信」ボタン。モーダルを開き、宛先を booking.contact_email から
     * プレフィル (未登録なら空)、本文を現在の返信フォーム入力からプレフィル。
     * 件名は毎回空 (デフォルト無し方針)。
     */
    public function openEmailModal(): void
    {
        $booking = $this->booking;
        $this->emailTo = $booking !== null ? (string) ($booking->contact_email ?? '') : '';
        $this->emailSubject = '';
        $this->emailBody = $this->body;
        $this->emailIncludeNoReplyFooter = true;
        $this->emailModalOpen = true;
    }

    public function closeEmailModal(): void
    {
        $this->emailModalOpen = false;
    }

    /**
     * メール送信モーダル内で定型文を挿入。返信フォームの useTemplate と同じく
     * Blade 変数展開を行い、既存入力があれば末尾追記、なければ置換。
     */
    public function useTemplateForEmail(int $templateId): void
    {
        $template = MessageTemplate::query()->find($templateId);
        if ($template === null) {
            return;
        }
        $this->insertRenderedEmailBody($template->body, "template#{$templateId}");
    }

    public function useAutoMessageRuleForEmail(int $ruleId): void
    {
        $rule = AutoMessageRule::query()->find($ruleId);
        if ($rule === null) {
            return;
        }
        $this->insertRenderedEmailBody($rule->body, "auto_message_rule#{$ruleId}");
    }

    private function insertRenderedEmailBody(string $rawBody, string $sourceLabel): void
    {
        $body = $rawBody;
        $booking = $this->booking;
        if ($booking !== null) {
            try {
                $body = app(BodyRenderer::class)->render($body, $booking);
            } catch (Throwable $e) {
                Log::warning('email template variable render failed', [
                    'source' => $sourceLabel,
                    'booking_id' => $booking->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->emailBody = trim($this->emailBody) === ''
            ? $body
            : rtrim($this->emailBody)."\n\n".$body;
    }

    /**
     * モーダルの「送信」。宛先を booking に保存、Blade 変数を最終展開してメール送信、
     * 会話タイムラインにメール送信済み system 行を追加。beds24 chat には投稿しない。
     */
    public function sendEmail(): void
    {
        $this->validate([
            'emailTo' => ['required', 'email', 'max:255'],
            'emailSubject' => ['required', 'string', 'max:255'],
            'emailBody' => ['required', 'string', 'max:10000'],
        ]);

        $booking = $this->booking;
        $latest = $this->messages->last();
        if ($booking === null || $latest === null) {
            Notification::make()->title('予約情報が見つかりません')->danger()->send();

            return;
        }

        // 送信直前に Blade 変数展開 (本文欄で {{ $... }} を書いた場合の展開)
        $rendered = $this->emailBody;
        try {
            $rendered = app(BodyRenderer::class)->render($this->emailBody, $booking);
        } catch (Throwable $e) {
            Log::warning('email body render failed on send', [
                'booking_id' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            Mail::to($this->emailTo)->send(new GuestRegistrationLinkMail(
                subjectLine: $this->emailSubject,
                bodyRendered: $rendered,
                includeNoReplyFooter: $this->emailIncludeNoReplyFooter,
            ));
        } catch (Throwable $e) {
            Log::warning('email send failed', [
                'booking_id' => $booking->id,
                'to' => $this->emailTo,
                'error' => $e->getMessage(),
            ]);
            Notification::make()->title('メール送信に失敗しました')->body($e->getMessage())->danger()->send();

            return;
        }

        // 宛先を booking に保存 (次回モーダルでプレフィル)
        if ($booking->contact_email !== $this->emailTo) {
            $booking->update(['contact_email' => $this->emailTo]);
            unset($this->booking);
        }

        // 履歴に「📧 メール送信」system 行を追加 (beds24 未投稿、message は送信本文)
        Message::create([
            'beds24_message_id' => null,
            'beds24_booking_id' => $latest->beds24_booking_id,
            'beds24_property_id' => $latest->beds24_property_id,
            'beds24_room_id' => $latest->beds24_room_id,
            'source' => Message::SOURCE_SYSTEM,
            'message' => $rendered,
            'email_subject' => $this->emailSubject,
            'emailed_to' => $this->emailTo,
            'emailed_at' => now(),
            'is_read' => true,
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        Notification::make()->title('メールを送信しました')->success()->send();

        $this->emailModalOpen = false;
        $this->emailSubject = '';
        $this->emailBody = '';
        unset($this->messages);
    }
}
