<?php

namespace App\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\AutoMessageSend;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * 予約 1 件に対して、マッチする全ルールの pending 送信を発行 or 更新する。
 *
 * SyncBookingsJob から booking upsert 直後に呼ばれる想定。
 * 冪等: 同じ (rule, booking) の pending は scheduled_at を上書き。
 *       sent / dry_run / skipped / failed は触らない（履歴を保持）。
 */
class AutoMessageScheduler
{
    public function __construct(
        private readonly ScheduledAtCalculator $calculator,
        private readonly ChannelMatcher $channelMatcher,
        private readonly EnabledFromGuard $enabledFromGuard,
    ) {}

    /**
     * 予約に紐づく pending の生成・更新をまとめて行う。
     * キャンセル済み予約は「キャンセル時トリガー」のみ pending 作成し、他は作らない。
     * 既存 pending は Dispatcher 側で SKIP_CANCELLED として skipped に落ちる。
     */
    public function scheduleFor(Booking $booking): void
    {
        $listing = $booking->listing;
        if ($listing === null) {
            return;
        }

        $isCancelled = $booking->status === Booking::STATUS_CANCELLED;

        $rules = AutoMessageRule::query()
            ->whereHas('listings', fn ($q) => $q->where('listings.id', $listing->id))
            ->when($isCancelled, fn ($q) => $q->where('trigger_event', AutoMessageRule::EVENT_CANCELLED))
            ->get();

        foreach ($rules as $rule) {
            $this->upsertPending($rule, $booking);
        }
    }

    private function upsertPending(AutoMessageRule $rule, Booking $booking): void
    {
        if ($this->enabledFromGuard->blocks($rule, $booking)) {
            return;
        }

        if (! $this->channelMatcher->matches($rule->channel_scope, $booking->api_source)) {
            return;
        }

        $scheduledAt = $this->calculator->calculate($rule, $booking);
        if ($scheduledAt === null) {
            return;
        }

        // checkin / checkout 系は「指定時刻ちょうどに 1 回だけ送る」が意図。
        // scheduled_at が既に過去なら発火窓を逃しているので pending を作らない。
        // booking_created / cancelled は予約イベント基点なので過去でも即発火を許容。
        if (in_array($rule->trigger_event, [AutoMessageRule::EVENT_CHECKIN, AutoMessageRule::EVENT_CHECKOUT], true)
            && $scheduledAt->isPast()
        ) {
            return;
        }

        DB::transaction(function () use ($rule, $booking, $scheduledAt) {
            $existing = AutoMessageSend::query()
                ->where('auto_message_rule_id', $rule->id)
                ->where('booking_id', $booking->id)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                AutoMessageSend::create([
                    'auto_message_rule_id' => $rule->id,
                    'booking_id' => $booking->id,
                    'scheduled_at' => $scheduledAt,
                    'status' => AutoMessageSend::STATUS_PENDING,
                ]);

                return;
            }

            if ($existing->status === AutoMessageSend::STATUS_PENDING) {
                $existing->update(['scheduled_at' => $scheduledAt]);
            }
            // sent / dry_run / skipped / failed は履歴として保持
        });
    }
}
