<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $beds24_booking_id
 * @property int|null $beds24_property_id
 * @property int|null $beds24_room_id
 * @property int|null $beds24_master_id
 * @property string|null $status
 * @property string|null $api_source
 * @property string|null $channel
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $email
 * @property string|null $mobile
 * @property Carbon|null $arrival
 * @property Carbon|null $departure
 * @property int|null $num_adult
 * @property int|null $num_child
 * @property Carbon|null $booking_time
 * @property Carbon|null $modified_time
 * @property Carbon|null $cancel_time
 * @property bool $auto_message_opt_out
 * @property int|null $guest_channel_id
 * @property string|null $chat_token
 * @property array<string, mixed> $raw_payload
 * @property Carbon $fetched_at
 * @property-read Property|null $property
 * @property-read Listing|null $listing
 * @property-read GuestRegistrationToken|null $guestRegistrationToken
 * @property-read Collection<int, GuestRegistration> $guestRegistrations
 * @property-read Collection<int, AutoMessageSend> $autoMessageSends
 */
#[Fillable([
    'beds24_booking_id',
    'beds24_property_id',
    'beds24_room_id',
    'beds24_master_id',
    'status',
    'api_source',
    'channel',
    'first_name',
    'last_name',
    'email',
    'contact_email',
    'mobile',
    'arrival',
    'departure',
    'num_adult',
    'num_child',
    'booking_time',
    'modified_time',
    'cancel_time',
    'auto_message_opt_out',
    'guest_channel_id',
    'chat_token',
    'raw_payload',
    'fetched_at',
])]
class Booking extends Model
{
    public const STATUS_NEW = 'new';

    public const STATUS_CONFIRMED = 'confirmed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_REQUEST = 'request';

    public const STATUS_BLACK = 'black';

    protected function casts(): array
    {
        return [
            'beds24_booking_id' => 'integer',
            'beds24_property_id' => 'integer',
            'beds24_room_id' => 'integer',
            'beds24_master_id' => 'integer',
            'arrival' => 'date',
            'departure' => 'date',
            'num_adult' => 'integer',
            'num_child' => 'integer',
            'booking_time' => 'datetime',
            'modified_time' => 'datetime',
            'cancel_time' => 'datetime',
            'auto_message_opt_out' => 'boolean',
            'fetched_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Property, $this>
     */
    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class, 'beds24_property_id', 'beds24_property_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'beds24_room_id', 'beds24_room_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'beds24_booking_id', 'beds24_booking_id');
    }

    /**
     * 自社サイト直接予約で、問い合わせ由来の場合に link される GuestChannel。
     * ShowConversation で問い合わせ履歴を混ぜて表示するために使う。
     *
     * @return BelongsTo<GuestChannel, $this>
     */
    public function guestChannel(): BelongsTo
    {
        return $this->belongsTo(GuestChannel::class);
    }

    /**
     * @return HasOne<GuestRegistrationToken, $this>
     */
    public function guestRegistrationToken(): HasOne
    {
        return $this->hasOne(GuestRegistrationToken::class);
    }

    /**
     * @return HasMany<GuestRegistration, $this>
     */
    public function guestRegistrations(): HasMany
    {
        return $this->hasMany(GuestRegistration::class)->orderBy('guest_number');
    }

    /**
     * @return HasMany<AutoMessageSend, $this>
     */
    public function autoMessageSends(): HasMany
    {
        return $this->hasMany(AutoMessageSend::class);
    }

    /**
     * ゲスト情報登録が「未完了」かを 3 条件の OR で判定する。
     * 自動リマインド (AutoMessageRule::CONDITION_REGISTRATION_INCOMPLETE) の発火判断に使う。
     *
     * 未完了と見なす条件:
     *   (1) 登録件数が予約人数 (num_adult + num_child) に満たない
     *       - 登録 0 件 (未登録) もこのケースに含まれる
     *   (2) 非日本人ゲストで身分証画像の verdict が OK になっていない
     *       - nationality != 'JP' かつ id_image_verdict != VERDICT_OK
     *
     * num_adult / num_child が両方 null の予約は「人数不明」なので未完了扱い (安全側)。
     */
    /**
     * 自社サイト直接予約か (api_source が jp-bnb / Direct のいずれか)。
     * beds24 は POST 時に apiSource='direct' を受けるが同期で返す値は 'Direct' に
     * 書き換わるため両方を許容する。
     */
    public function isDirectBooking(): bool
    {
        $source = strtolower((string) ($this->api_source ?? ''));

        return $source === 'direct' || $source === 'direct';
    }

    public function isRegistrationIncomplete(): bool
    {
        $registrations = $this->guestRegistrations;

        $expected = (int) ($this->num_adult ?? 0) + (int) ($this->num_child ?? 0);
        if ($expected === 0) {
            return true;
        }

        if ($registrations->count() < $expected) {
            return true;
        }

        foreach ($registrations as $registration) {
            $isJapanese = strtoupper((string) $registration->nationality) === 'JP';
            if ($isJapanese) {
                continue;
            }
            // 管理者判定が入っていれば優先 (effectiveVerdict)。AI 判定のみでも OK なら通す。
            if ($registration->effectiveVerdict() !== GuestRegistration::VERDICT_OK) {
                return true;
            }
        }

        return false;
    }
}
