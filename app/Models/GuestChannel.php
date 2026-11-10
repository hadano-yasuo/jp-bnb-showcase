<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * ホスト⇔ゲスト間のプライベート通信チャネル。
 * memory: project_self_hp_channel
 *
 * 起点は 2 通り:
 * - /contact 問い合わせフォーム submit
 * - /reserve 直接予約フォーム submit (問い合わせを経由しない場合も自動作成)
 *
 * @property int $id
 * @property string $token 48-char hash (公開 /chat/{token} で参照)
 * @property string|null $guest_name
 * @property string $guest_email
 * @property string $guest_locale
 * @property string|null $room_slug /contact で選択された対象部屋 (config/rooms.php の slug)
 * @property int|null $booking_id 予約成立時にリンク
 * @property Carbon $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Booking|null $booking
 * @property-read Collection<int, GuestChannelMessage> $messages
 */
#[Fillable([
    'token',
    'guest_name',
    'guest_email',
    'guest_locale',
    'room_slug',
    'booking_id',
    'expires_at',
])]
class GuestChannel extends Model
{
    /** チャネル有効期限 (開設から)。予約成立で departure+7日 に延長。 */
    public const DEFAULT_EXPIRES_DAYS = 90;

    /** 予約成立後の延長 (departure から) */
    public const POST_BOOKING_EXTENSION_DAYS = 7;

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return HasMany<GuestChannelMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(GuestChannelMessage::class)->orderBy('sent_at')->orderBy('id');
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * 48 文字のランダムトークンを生成 (GuestRegistrationToken と同構造)
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }
}
