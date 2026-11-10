<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * ルール × 予約 単位の送信予定エントリ。
 *
 * @property int $id
 * @property int $auto_message_rule_id
 * @property int $booking_id
 * @property Carbon $scheduled_at
 * @property string $status
 * @property string|null $skip_reason
 * @property string|null $rendered_body
 * @property string|null $rendered_body_translated
 * @property string|null $translated_to_lang
 * @property int|null $beds24_message_id
 * @property Carbon|null $sent_at
 * @property string|null $error
 * @property-read AutoMessageRule $rule
 * @property-read Booking $booking
 */
#[Fillable([
    'auto_message_rule_id',
    'booking_id',
    'scheduled_at',
    'status',
    'skip_reason',
    'rendered_body',
    'rendered_body_translated',
    'translated_to_lang',
    'beds24_message_id',
    'sent_at',
    'error',
])]
class AutoMessageSend extends Model
{
    /**
     * timestamptz 列に INSERT する際に timezone offset まで含めるための書式。
     * これが無いと Carbon in JST の wall-clock がそのまま naive 文字列として渡り、
     * PG session TZ=UTC で UTC moment として誤解釈される（+9h ズレ）。
     * 詳細は Message::$dateFormat 参照。
     */
    protected $dateFormat = 'Y-m-d H:i:sO';

    public const STATUS_PENDING = 'pending';

    public const STATUS_DRY_RUN = 'dry_run';

    public const STATUS_SENT = 'sent';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_FAILED = 'failed';

    public const SKIP_CANCELLED = 'cancelled';

    public const SKIP_PAST = 'past_arrival';

    public const SKIP_CHANNEL_MISMATCH = 'channel_mismatch';

    public const SKIP_LISTING_UNLINKED = 'listing_unlinked';

    public const SKIP_AFTER_DEPARTURE = 'after_departure';

    public const SKIP_OPTED_OUT = 'booking_opted_out';

    public const SKIP_BEFORE_ENABLED_FROM = 'booking_before_enabled_from';

    // 本文に guest 名前系変数（guest_first_name / guest_last_name / guest_full_name）が
    // 含まれるのに booking 側の値が空。宛名なしの失礼な送信を避けるため skip。
    // 名前が後から埋まれば手動 retry で復活させる想定（現状 retry UI 未実装）。
    public const SKIP_EMPTY_GUEST_NAME = 'empty_guest_name';

    // rule->condition が満たされない (登録完了済み等)。
    // ゲスト情報リマインドで既に登録完了しているゲストへ送らないための判定。
    public const SKIP_CONDITION_UNMET = 'condition_unmet';

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'beds24_message_id' => 'integer',
        ];
    }

    /**
     * PG の timestamptz は "+00" 付きで返るので Carbon は UTC のまま。
     * 表示や滞在中判定は JST 基準なので Carbon をアプリ TZ へ寄せる。
     */
    protected function asDateTime($value): Carbon
    {
        return parent::asDateTime($value)->setTimezone(config('app.timezone'));
    }

    /**
     * @return BelongsTo<AutoMessageRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(AutoMessageRule::class, 'auto_message_rule_id');
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
