<?php

namespace App\Services\GuestRegistration;

use App\Models\Booking;
use App\Models\GuestRegistrationToken;

/**
 * 予約に対してゲスト登録用のトークンを自動発行する。
 *
 * 発行対象:
 *  - status が black / cancelled 以外
 *  - departure が非 null
 *  - departure が 30 日以上前でない（過去分の一斉トークン発行を避ける）
 *
 * 発行は firstOrCreate で冪等。expires_at は departure + 7 日終了時刻。
 * 管理者が Filament から手動延長した場合は上書きしない。
 */
class GuestRegistrationTokenGenerator
{
    public function ensureFor(Booking $booking): ?GuestRegistrationToken
    {
        if (in_array($booking->status, [Booking::STATUS_BLACK, Booking::STATUS_CANCELLED], true)) {
            return null;
        }

        if ($booking->departure === null) {
            return null;
        }

        // 過去 30 日以内 or 未来のみ対象。それ以前の予約は名簿保管期間内でも
        // 実運用でフォーム URL を送る意義が薄いためトークンを発行しない。
        if ($booking->departure->lt(now()->subDays(30)->startOfDay())) {
            return null;
        }

        return GuestRegistrationToken::firstOrCreate(
            ['booking_id' => $booking->id],
            [
                'token' => GuestRegistrationToken::generateToken(),
                'expires_at' => $booking->departure->copy()->addDays(7)->endOfDay(),
            ]
        );
    }
}
