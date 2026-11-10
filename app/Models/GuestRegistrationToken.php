<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ゲスト向け登録フォームへの公開URLを守るトークン。1予約に1件。
 *
 * expires_at 到達で無効扱い。管理者が Filament から手動延長可能。
 *
 * manually_completed_at は、メール等でゲスト情報を受領済みのため URL 経由の
 * 登録が不要になった場合に管理者が立てるフラグ。実データ (guest_registrations)
 * は作らず、一覧上「収集完了」として扱うためだけに使う。
 *
 * @property int $id
 * @property int $booking_id
 * @property string $token
 * @property Carbon $expires_at
 * @property Carbon|null $manually_completed_at
 * @property int|null $manually_completed_by
 * @property string|null $manually_completed_note
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking $booking
 * @property-read User|null $manuallyCompletedBy
 */
#[Fillable(['booking_id', 'token', 'expires_at', 'manually_completed_at', 'manually_completed_by', 'manually_completed_note'])]
class GuestRegistrationToken extends Model
{
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'manually_completed_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isManuallyCompleted(): bool
    {
        return $this->manually_completed_at !== null;
    }

    public static function generateToken(): string
    {
        return Str::random(48);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function manuallyCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manually_completed_by');
    }
}
