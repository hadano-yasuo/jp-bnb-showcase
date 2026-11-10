<?php

namespace App\Services\Beds24;

use App\Models\Booking;
use App\Services\AutoMessage\AutoMessageScheduler;
use App\Services\GuestRegistration\GuestRegistrationTokenGenerator;
use Illuminate\Support\Carbon;

/**
 * beds24 の 1 予約 payload を Booking モデルへ UPSERT する。
 *
 * 元は SyncBookingsJob::upsertBooking にあったロジックだが、Webhook 受信でも
 * 同じ処理が必要になったため service に切り出して両方から使う。
 *
 * UPSERT に付随して:
 *  - ゲスト登録トークンを ensure（新規予約時のみ生成）
 *  - 自動送信ルールに従って auto_message_sends を schedule
 */
class BookingUpserter
{
    public function __construct(
        private readonly GuestRegistrationTokenGenerator $tokenGenerator,
        private readonly AutoMessageScheduler $scheduler,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  beds24 の /bookings item 相当
     */
    public function upsert(array $payload): Booking
    {
        $booking = Booking::updateOrCreate(
            ['beds24_booking_id' => $payload['id']],
            [
                'beds24_property_id' => $payload['propertyId'] ?? null,
                'beds24_room_id' => $payload['roomId'] ?? null,
                'beds24_master_id' => $payload['masterId'] ?? null,
                'status' => $payload['status'] ?? null,
                'api_source' => $payload['apiSource'] ?? null,
                'channel' => $payload['channel'] ?? null,
                'first_name' => $payload['firstName'] ?? null,
                'last_name' => $payload['lastName'] ?? null,
                'email' => $payload['email'] ?? null,
                'mobile' => $payload['mobile'] ?? null,
                'arrival' => $payload['arrival'] ?? null,
                'departure' => $payload['departure'] ?? null,
                'num_adult' => $payload['numAdult'] ?? null,
                'num_child' => $payload['numChild'] ?? null,
                // beds24 payload は "Z" 付き ISO 8601 UTC。Carbon::parse だと UTC
                // Carbon になるが、PG session=JST では naive 文字列を JST として
                // 解釈するため、ここでアプリ TZ に寄せてから保存する。
                'booking_time' => $this->toAppTz($payload['bookingTime'] ?? null),
                'modified_time' => $this->toAppTz($payload['modifiedTime'] ?? null),
                'cancel_time' => $this->toAppTz($payload['cancelTime'] ?? null),
                'raw_payload' => $payload,
                'fetched_at' => now(),
            ]
        );

        $this->tokenGenerator->ensureFor($booking);
        $this->scheduler->scheduleFor($booking);

        return $booking;
    }

    private function toAppTz(?string $iso): ?Carbon
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso)->setTimezone(config('app.timezone'));
    }
}
