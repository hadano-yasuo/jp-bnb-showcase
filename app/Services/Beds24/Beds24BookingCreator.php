<?php

namespace App\Services\Beds24;

use App\Filament\Pages\Conversations\ShowConversation;
use App\Mail\ContactSubmissionMail;
use App\Mail\ReservationConfirmationMail;
use App\Models\Booking;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use App\Models\Listing;
use App\Models\Message;
use App\Models\PendingReservation;
use App\Services\AutoMessage\AutoMessageScheduler;
use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\StripeClient;
use Throwable;

/**
 * Stripe webhook (checkout.session.completed) 受信後に実行する。
 *
 * フロー:
 *   1. PendingReservation から form_data を取得
 *   2. Beds24Client::post('/bookings', ...) で予約成立
 *   3. 返却された bookingId をローカル Booking にリンク (SyncBookingsJob が後で upsert)
 *   4. ローカル messages に「予約確定」system Message を insert
 *      (beds24 messages API には POST しない。公開HP (example.com) 経由の予約は
 *       beds24 側の messaging を使わない方針)
 *   5. 問い合わせ (inquiry_token) 由来なら bookings.guest_channel_id に link
 *      → ShowConversation で問い合わせ履歴 + 予約確定 Message を時系列で混ぜて表示
 *   6. ゲスト/ホストにメール送信
 *   7. PendingReservation.status = paid、booking_id 設定
 *
 * 失敗時: status = failed、error 列に詳細、ホストに通知 (手動対応へ)
 *
 * 必要な beds24 scope: write:bookings, write:bookings-personal
 */
class Beds24BookingCreator
{
    public function __construct(
        private readonly Beds24Client $client,
        private readonly AutoMessageScheduler $scheduler,
    ) {}

    /**
     * webhook のメインエントリ。pending が paid 済みなら冪等でスキップ。
     *
     * @return bool true=新規に処理した / false=既に処理済み or 対象外
     */
    public function fulfill(PendingReservation $pending): bool
    {
        if ($pending->status !== PendingReservation::STATUS_PENDING) {
            // 冪等性: 既に paid/failed/expired なら触らない
            return false;
        }

        try {
            $booking = DB::transaction(fn () => $this->createBookingAndChannel($pending));
            $this->notifyGuestAndHost($pending, $booking);

            return true;
        } catch (Throwable $e) {
            $this->markFailed($pending, $e);

            return false;
        }
    }

    private function createBookingAndChannel(PendingReservation $pending): Booking
    {
        $data = $pending->form_data;
        $roomId = $this->resolveRoomId($data);
        $propertyId = $this->resolvePropertyId($roomId);
        [$firstName, $lastName] = $this->splitName((string) ($data['name'] ?? ''));

        // レースコンディション対策: 決済中 (ReserveController::store 〜 webhook 到達の間) に
        // 他の予約が同期間に入ってしまっていないか再度チェック。ある場合は beds24 に
        // POST せずに例外を投げ、markFailed で [要対応] 通知 + 返金指示をホストに送る。
        $this->guardAgainstOverlap($roomId, (string) $data['arrival'], (string) $data['departure']);

        $response = $this->client->post('/bookings', [[
            'roomId' => $roomId,
            'status' => Booking::STATUS_CONFIRMED,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => (string) ($data['email'] ?? ''),
            'mobile' => (string) ($data['phone'] ?? ''),
            'arrival' => (string) $data['arrival'],
            'departure' => (string) $data['departure'],
            'numAdult' => (int) $data['num_adult'],
            'numChild' => (int) ($data['num_child'] ?? 0),
            'price' => $pending->amount_jpy,
            'notes' => (string) ($data['notes'] ?? ''),
            'apiSource' => 'direct',
        ]]);

        $first = $response[0] ?? null;
        if (! is_array($first) || ($first['success'] ?? false) !== true) {
            throw new Beds24Exception(
                message: 'beds24 /bookings POST が失敗',
                context: ['response' => $response, 'pending_id' => $pending->id],
            );
        }

        $new = $first['new'] ?? null;
        $beds24BookingId = isset($new['id']) ? (int) $new['id'] : null;
        if ($beds24BookingId === null) {
            throw new Beds24Exception(
                message: 'beds24 /bookings POST レスポンスに id が無い',
                context: ['response' => $response, 'pending_id' => $pending->id],
            );
        }

        // GuestChannel を必ず link する:
        //   - 問い合わせ由来 (pending.guest_channel_id あり): 既存 channel を流用し、
        //     GuestChannelMessage を Message に移送する
        //   - 直接予約 (問い合わせ経由なし): 新規作成する
        // → ゲスト宛メール (ReservationConfirmationMail / AutoMessageMail) に常に
        //    /chat/{token} の URL を貼れるようにする。
        $channelId = $pending->guest_channel_id
            ?? $this->createGuestChannelForBooking($data)->id;

        // ローカル Booking を仮の情報で upsert (詳細は SyncBookingsJob の次回同期で埋まる)
        $booking = Booking::updateOrCreate(
            ['beds24_booking_id' => $beds24BookingId],
            [
                'beds24_room_id' => $roomId,
                'beds24_property_id' => isset($new['propertyId']) ? (int) $new['propertyId'] : $propertyId,
                'status' => Booking::STATUS_CONFIRMED,
                'api_source' => 'direct',
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => (string) ($data['email'] ?? ''),
                'mobile' => (string) ($data['phone'] ?? ''),
                'arrival' => (string) $data['arrival'],
                'departure' => (string) $data['departure'],
                'num_adult' => (int) $data['num_adult'],
                'num_child' => (int) ($data['num_child'] ?? 0),
                'booking_time' => now(),
                'guest_channel_id' => $channelId,
                'raw_payload' => $new,
                'fetched_at' => now(),
            ],
        );

        // 問い合わせ由来の場合は GuestChannelMessage を Message に移送 (直接予約は履歴なしで no-op)。
        // 予約成立後の会話は Messages 単一ソースで統一し、ShowConversation 側で
        // GuestChannelMessage を混ぜる処理を不要にする。
        $this->migrateInquiryMessagesToBooking($channelId, $booking);
        GuestChannel::where('id', $channelId)->update(['booking_id' => $booking->id]);

        // 「予約確定」をローカル Message に insert (beds24 messages API には投げない)
        // beds24_message_id = null、source = system、 ShowConversation / ゲスト側 /chat でも表示される。
        // ゲスト向け文面のため「自社サイト」等の運営者視点の文言は避ける。
        // 由来表示は public_hp.domain (SaaS 展開時はテナントごとに変わる想定)。
        $originLabel = (string) config('public_hp.domain', '');
        Message::create([
            'beds24_message_id' => null,
            'beds24_booking_id' => $beds24BookingId,
            'beds24_property_id' => $booking->beds24_property_id,
            'beds24_room_id' => $roomId,
            'source' => Message::SOURCE_SYSTEM,
            'message' => sprintf(
                "ご予約を承りました%s\n%s 〜 %s\n大人 %d 名 / 子供 %d 名\n金額: ¥%s",
                $originLabel !== '' ? " ({$originLabel} 経由・決済済)" : '',
                $data['arrival'],
                $data['departure'],
                (int) $data['num_adult'],
                (int) ($data['num_child'] ?? 0),
                number_format($pending->amount_jpy),
            ),
            'is_read' => true,
            'sent_at' => now(),
            'fetched_at' => now(),
            'raw_payload' => [],
        ]);

        $pending->update([
            'status' => PendingReservation::STATUS_PAID,
            'booking_id' => $booking->id,
        ]);

        // SyncBookingsJob を待たずに即座に auto-message をスケジュール化する
        // (次の 15 分 cron 待ちだと直前予約でも対応が遅れるため)。
        $this->scheduler->scheduleFor($booking);

        return $booking;
    }

    private function notifyGuestAndHost(PendingReservation $pending, Booking $booking): void
    {
        $data = $pending->form_data;

        // ゲスト宛: 予約完了メール (多言語対応、ReservationConfirmationMail)
        Mail::to((string) ($data['email'] ?? ''))
            ->send(new ReservationConfirmationMail(
                booking: $booking,
                guestLocale: (string) ($data['locale'] ?? 'ja'),
            ));

        // ホスト宛: 新規予約通知 (件名を直接予約用に変更)
        $guestName = trim((string) ($data['name'] ?? '')) ?: '（名前未入力）';
        $subject = sprintf(
            '【新規予約】%s 様 %s〜%s / ¥%s',
            $guestName,
            (string) $data['arrival'],
            (string) $data['departure'],
            number_format($pending->amount_jpy),
        );

        // admin リンク: 常にメッセージ画面 (ShowConversation) に誘導。
        // 問い合わせ由来の場合も、ShowConversation 側で GuestChannelMessage を
        // 合流表示するので一箇所で全部見える。
        $adminUrl = $this->adminUrlForBooking($booking->beds24_booking_id);

        Mail::to(config('mail.from.address'))
            ->send(new ContactSubmissionMail(
                data: [
                    '予約' => 'beds24 #'.$booking->beds24_booking_id,
                    '物件' => $booking->listing !== null
                        ? $booking->listing->display_name
                        : ('roomId '.$booking->beds24_room_id),
                    'チェックイン' => (string) $data['arrival'],
                    'チェックアウト' => (string) $data['departure'],
                    '大人' => (string) (int) $data['num_adult'],
                    '子供' => (string) (int) ($data['num_child'] ?? 0),
                    '氏名' => $guestName,
                    'メール' => (string) ($data['email'] ?? ''),
                    '電話' => (string) ($data['phone'] ?? ''),
                    '金額' => '¥'.number_format($pending->amount_jpy).' (税込・決済済)',
                    'メモ' => (string) ($data['notes'] ?? ''),
                ],
                adminUrl: $adminUrl,
                subjectOverride: $subject,
                headline: '新規予約',
            ));
    }

    private function markFailed(PendingReservation $pending, Throwable $e): void
    {
        Log::error('Beds24BookingCreator failed', [
            'pending_id' => $pending->id,
            'error' => $e->getMessage(),
        ]);

        $pending->update([
            'status' => PendingReservation::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 1000),
        ]);

        // Stripe で既に capture 済みなので全額返金を自動実行する
        $refundStatus = $this->autoRefund($pending);

        // ホストに通知 (返金結果込み)
        try {
            $guestName = trim((string) ($pending->form_data['name'] ?? '')) ?: '（名前未入力）';
            Mail::to(config('mail.from.address'))->send(new ContactSubmissionMail(
                data: [
                    'pending_id' => (string) $pending->id,
                    'stripe_session' => (string) $pending->stripe_session_id,
                    '氏名' => $guestName,
                    '金額' => '¥'.number_format($pending->amount_jpy),
                    '返金ステータス' => $refundStatus,
                    'エラー' => mb_substr($e->getMessage(), 0, 500),
                    'form_data' => json_encode($pending->form_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '',
                ],
                adminUrl: '',
                subjectOverride: "[要対応] 直接予約 失敗 - {$guestName} 様 (pending#{$pending->id})",
                headline: '[要対応] 直接予約の beds24 成立失敗',
            ));
        } catch (Throwable) {
            // 通知自体の失敗は無視（元のエラーが大事）
        }
    }

    /**
     * 直接予約 (問い合わせ経由なし) 用に GuestChannel を新規作成する。
     * ゲスト宛メールの /chat/{token} リンク生成に使う。expires_at は
     * departure + POST_BOOKING_EXTENSION_DAYS。
     *
     * @param  array<string, mixed>  $data
     */
    private function createGuestChannelForBooking(array $data): GuestChannel
    {
        return GuestChannel::create([
            'token' => GuestChannel::generateToken(),
            'guest_name' => trim((string) ($data['name'] ?? '')) ?: null,
            'guest_email' => (string) ($data['email'] ?? ''),
            'guest_locale' => (string) ($data['locale'] ?? 'ja'),
            'room_slug' => (string) ($data['room_slug'] ?? ''),
            'expires_at' => Carbon::parse((string) $data['departure'])
                ->endOfDay()
                ->addDays(GuestChannel::POST_BOOKING_EXTENSION_DAYS),
        ]);
    }

    /**
     * 問い合わせ由来の予約の場合、問い合わせ時に蓄積した GuestChannelMessage を
     * Booking の Message として複製する。GuestChannelMessage は履歴として残す
     * (/chat/{token} の guest 側表示が壊れないように)。
     *
     * source マッピング: guest/host/system はそのまま流用。
     * メール配信ログ (system + emailed_to) は Message 側でも system として記録。
     */
    private function migrateInquiryMessagesToBooking(int $channelId, Booking $booking): void
    {
        $messages = GuestChannelMessage::query()
            ->where('guest_channel_id', $channelId)
            ->orderBy('sent_at')
            ->orderBy('id')
            ->get();

        foreach ($messages as $gc) {
            Message::create([
                'beds24_message_id' => null,
                'beds24_booking_id' => $booking->beds24_booking_id,
                'beds24_property_id' => $booking->beds24_property_id,
                'beds24_room_id' => $booking->beds24_room_id,
                'source' => $gc->source,
                'message' => $gc->message,
                'message_ja' => $gc->message_ja,
                'detected_language' => $gc->detected_language,
                'detected_language_label' => $gc->detected_language_label,
                'email_subject' => $gc->email_subject,
                'emailed_to' => $gc->emailed_to,
                'emailed_at' => $gc->emailed_at,
                'is_read' => true,
                'sent_at' => $gc->sent_at,
                'fetched_at' => now(),
                'raw_payload' => [],
            ]);
        }
    }

    /**
     * Stripe refund API で全額返金を試みる。成功/失敗/スキップのステータス文字列を返す。
     * (ホスト通知メールに記載するため)
     */
    private function autoRefund(PendingReservation $pending): string
    {
        if ($pending->stripe_session_id === null || $pending->stripe_session_id === '') {
            return 'スキップ (stripe_session_id なし)';
        }

        try {
            $stripe = app(StripeClient::class);
            $session = $stripe->checkout->sessions->retrieve($pending->stripe_session_id);
            $paymentIntentId = $session->payment_intent;
            if (! is_string($paymentIntentId) || $paymentIntentId === '') {
                return 'スキップ (payment_intent なし、未決済の可能性)';
            }

            $refund = $stripe->refunds->create([
                'payment_intent' => $paymentIntentId,
                'reason' => 'requested_by_customer',
                'metadata' => ['pending_reservation_id' => (string) $pending->id],
            ]);

            Log::info('Stripe refund issued', [
                'pending_id' => $pending->id,
                'refund_id' => $refund->id,
                'amount' => $refund->amount,
            ]);

            return "✓ 返金済 (refund_id: {$refund->id}, ¥".number_format((int) $refund->amount).')';
        } catch (Throwable $re) {
            Log::error('Stripe refund failed', [
                'pending_id' => $pending->id,
                'error' => $re->getMessage(),
            ]);

            return '✗ 返金失敗 (手動対応要): '.mb_substr($re->getMessage(), 0, 200);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveRoomId(array $data): int
    {
        $slug = (string) ($data['room_slug'] ?? '');
        $roomId = (int) config('rooms.'.$slug.'.beds24_room_id', 0);
        if ($roomId === 0) {
            throw new Beds24Exception(
                message: "config/rooms.php から beds24_room_id を解決できない: {$slug}",
            );
        }

        return $roomId;
    }

    /**
     * ShowConversation (beds24 booking 単位の会話詳細) の admin URL を
     * APP_URL 固定で生成する。
     *
     * Stripe webhook は公開HPドメイン (example.com) に届くため、Laravel の
     * URL ジェネレータが request host を使って `example.com/admin/...` を
     * 吐いてしまう。admin は admin.example.com に固定したいので、
     * host 部分を APP_URL ホストで置き換える。
     */
    private function adminUrlForBooking(int $beds24BookingId): string
    {
        $url = ShowConversation::getUrl(['bookingId' => $beds24BookingId]);
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (! is_string($appHost) || $appHost === '') {
            return $url;
        }
        $currentHost = parse_url($url, PHP_URL_HOST);
        if (! is_string($currentHost) || $currentHost === $appHost) {
            return $url;
        }

        return (string) preg_replace('#://'.preg_quote($currentHost, '#').'#', '://'.$appHost, $url, 1);
    }

    /**
     * 決済中に別の予約が同期間に入っていないか再チェック。既存予約があれば
     * Beds24Exception を投げて webhook を失敗扱いに。ホスト通知で要返金が
     * 伝わる (markFailed)。departure 日 = 退出日なので境界重複は許容。
     *
     * 2段構え:
     *   1. ローカル bookings テーブル (SyncBookingsJob が 15 分おきに取り込み)
     *      → jp-bnb 自サイトの重複と、同期済みの OTA 予約を即検知
     *   2. beds24 /bookings 直問い合わせ (対象 room / 対象期間だけに絞る)
     *      → SyncBookingsJob 待ちの Airbnb/Booking.com 直近予約も捕捉
     *      コスト 1 credit/件、月数件規模なので rate limit 100/5min に対し無視できる
     */
    private function guardAgainstOverlap(int $beds24RoomId, string $arrival, string $departure): void
    {
        $this->guardLocalOverlap($beds24RoomId, $arrival, $departure);
        $this->guardBeds24Overlap($beds24RoomId, $arrival, $departure);
    }

    private function guardLocalOverlap(int $beds24RoomId, string $arrival, string $departure): void
    {
        $exists = Booking::query()
            ->where('beds24_room_id', $beds24RoomId)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->whereDate('arrival', '<', $departure)
            ->whereDate('departure', '>', $arrival)
            ->exists();

        if ($exists) {
            throw new Beds24Exception(
                message: 'レースコンディション: 決済中に同期間への別予約が入りました (ローカル検知)。要返金 (Stripe) + キャンセル対応',
                context: ['beds24_room_id' => $beds24RoomId, 'arrival' => $arrival, 'departure' => $departure],
            );
        }
    }

    /**
     * beds24 /bookings に対象 room / 対象期間で絞り込み問い合わせ。arrivalFrom は
     * 滞在開始が自分より早いが departure が自分の arrival より後の予約を取り込むため
     * 30 日前倒しする (民泊想定で最大滞在 < 30 日)。cancelled と inquiry は除外。
     */
    private function guardBeds24Overlap(int $beds24RoomId, string $arrival, string $departure): void
    {
        $arrivalCarbon = Carbon::parse($arrival);
        $departureCarbon = Carbon::parse($departure);

        $response = $this->client->get('/bookings', [
            'roomId' => $beds24RoomId,
            'arrivalFrom' => $arrivalCarbon->copy()->subDays(30)->toDateString(),
            'arrivalTo' => $departureCarbon->copy()->subDay()->toDateString(),
            'status' => ['confirmed', 'request', 'new', 'black'],
        ]);

        foreach ($response['data'] as $b) {
            $bArrival = (string) ($b['arrival'] ?? '');
            $bDeparture = (string) ($b['departure'] ?? '');
            if ($bArrival === '' || $bDeparture === '') {
                continue;
            }
            // 重複判定: 他予約の arrival < 自分の departure かつ 他予約の departure > 自分の arrival
            if ($bArrival < $departure && $bDeparture > $arrival) {
                throw new Beds24Exception(
                    message: 'レースコンディション: 決済中に同期間への別予約が入りました (beds24 検知)。要返金 (Stripe) + キャンセル対応',
                    context: [
                        'beds24_room_id' => $beds24RoomId,
                        'arrival' => $arrival,
                        'departure' => $departure,
                        'conflict_beds24_booking_id' => $b['id'] ?? null,
                        'conflict_arrival' => $bArrival,
                        'conflict_departure' => $bDeparture,
                        'conflict_api_source' => $b['apiSource'] ?? null,
                    ],
                );
            }
        }
    }

    /**
     * beds24 propertyId を Listing から解決。bookings テーブルの NOT NULL 制約
     * のため、ローカル Booking insert 時に必要。
     */
    private function resolvePropertyId(int $beds24RoomId): int
    {
        $listing = Listing::query()->where('beds24_room_id', $beds24RoomId)->first();
        if ($listing === null || (int) $listing->beds24_property_id === 0) {
            throw new Beds24Exception(
                message: "Listing から beds24_property_id を解決できない: roomId={$beds24RoomId}",
            );
        }

        return (int) $listing->beds24_property_id;
    }

    /**
     * フォームの氏名フィールドを last/first に分割する簡易ロジック。
     * スペース区切りで split、無ければ last_name にそのまま、first_name は空。
     *
     * @return array{0: string, 1: string} [firstName, lastName]
     */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name), 2) ?: [];
        if (count($parts) === 2) {
            return [$parts[1], $parts[0]];
        }

        return ['', $name];
    }
}
