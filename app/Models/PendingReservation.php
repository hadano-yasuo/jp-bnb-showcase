<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Stripe Checkout リダイレクト中の予約データ保持。
 *
 * ReserveController::store が作成し、Stripe webhook で beds24 予約成立後に
 * status=paid にして booking_id をリンクする。失敗時は status=failed。
 *
 * @property int $id
 * @property string|null $stripe_session_id
 * @property array<string, mixed> $form_data
 * @property int $amount_jpy
 * @property string $status
 * @property int|null $guest_channel_id
 * @property int|null $booking_id
 * @property string|null $error
 * @property Carbon $expires_at
 * @property-read GuestChannel|null $guestChannel
 * @property-read Booking|null $booking
 */
#[Fillable([
    'stripe_session_id',
    'form_data',
    'amount_jpy',
    'status',
    'guest_channel_id',
    'booking_id',
    'error',
    'expires_at',
])]
class PendingReservation extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    protected function casts(): array
    {
        return [
            'form_data' => 'array',
            'amount_jpy' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<GuestChannel, $this>
     */
    public function guestChannel(): BelongsTo
    {
        return $this->belongsTo(GuestChannel::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
