<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * 自動送信メッセージのルール。
 *
 * @property int $id
 * @property string $name
 * @property string $trigger_event
 * @property int|null $offset_minutes
 * @property int|null $offset_days
 * @property string|null $time_of_day
 * @property string $channel_scope
 * @property string|null $locale
 * @property string|null $condition dispatch 時評価する発火条件。null=無条件
 * @property string $body
 * @property bool $is_enabled
 * @property bool $only_when_staying
 * @property Carbon|null $enabled_from
 * @property-read Collection<int, Listing> $listings
 * @property-read Collection<int, AutoMessageSend> $sends
 */
#[Fillable([
    'name',
    'trigger_event',
    'offset_minutes',
    'offset_days',
    'time_of_day',
    'channel_scope',
    'locale',
    'condition',
    'body',
    'is_enabled',
    'only_when_staying',
    'enabled_from',
])]
class AutoMessageRule extends Model
{
    /**
     * timestamptz 列を offset 付きで INSERT し、PG session TZ=UTC での
     * JST wall-clock 誤解釈を防ぐ。詳細は Message::$dateFormat 参照。
     */
    protected $dateFormat = 'Y-m-d H:i:sO';

    public const EVENT_BOOKING_CREATED = 'booking_created';

    public const EVENT_CHECKIN = 'checkin';

    public const EVENT_CHECKOUT = 'checkout';

    public const EVENT_CANCELLED = 'cancelled';

    public const CHANNEL_ALL = 'all';

    public const CHANNEL_AIRBNB = 'airbnb';

    public const CHANNEL_BOOKING = 'booking';

    public const CHANNEL_DIRECT = 'direct';

    /**
     * ゲスト情報登録が未完了 (未登録・人数不足・非日本人 id 欠落 のいずれか) の場合のみ発火。
     * dispatch 時に Booking::isRegistrationIncomplete() で評価。
     */
    public const CONDITION_REGISTRATION_INCOMPLETE = 'registration_incomplete';

    /**
     * 発火条件コード → 表示名。null は「無条件」として扱う。
     * Filament の form / 一覧フィルタ / 表示列で共通に使う。
     *
     * @return array<string, string>
     */
    public static function conditionLabels(): array
    {
        return [
            self::CONDITION_REGISTRATION_INCOMPLETE => 'ゲスト情報登録が未完了',
        ];
    }

    protected function casts(): array
    {
        return [
            'offset_minutes' => 'integer',
            'offset_days' => 'integer',
            'is_enabled' => 'boolean',
            'only_when_staying' => 'boolean',
            'enabled_from' => 'datetime',
        ];
    }

    /**
     * PG の timestamptz は "+00" 付きで返るため Carbon は UTC のまま。
     * 表示・比較は JST 基準なのでアプリ TZ に寄せる。
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }

    /**
     * @return BelongsToMany<Listing, $this>
     */
    public function listings(): BelongsToMany
    {
        return $this->belongsToMany(Listing::class, 'auto_message_rule_listing');
    }

    /**
     * @return HasMany<AutoMessageSend, $this>
     */
    public function sends(): HasMany
    {
        return $this->hasMany(AutoMessageSend::class);
    }
}
