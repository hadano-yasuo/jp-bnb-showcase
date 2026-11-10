<?php

namespace App\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\Booking;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * ルールと予約から scheduled_at（Asia/Tokyo）を算出する。
 *
 * - booking_created: booking.booking_time + offset_minutes 分
 * - cancelled:       booking.cancel_time  + offset_minutes 分
 * - checkin:  booking.arrival   の日付 + offset_days 日 + time_of_day
 * - checkout: booking.departure の日付 + offset_days 日 + time_of_day
 *
 * 予約に必要な起点情報が欠けていたら null を返す。
 */
class ScheduledAtCalculator
{
    public function calculate(AutoMessageRule $rule, Booking $booking): ?Carbon
    {
        return match ($rule->trigger_event) {
            AutoMessageRule::EVENT_BOOKING_CREATED => $this->fromMinutes($rule, $booking->booking_time),
            AutoMessageRule::EVENT_CANCELLED => $this->fromMinutes($rule, $booking->cancel_time),
            AutoMessageRule::EVENT_CHECKIN => $this->fromDate($rule, $booking->arrival),
            AutoMessageRule::EVENT_CHECKOUT => $this->fromDate($rule, $booking->departure),
            default => throw new RuntimeException("unknown trigger_event: {$rule->trigger_event}"),
        };
    }

    private function fromMinutes(AutoMessageRule $rule, ?Carbon $base): ?Carbon
    {
        if ($base === null || $rule->offset_minutes === null) {
            return null;
        }

        return $base->copy()->addMinutes($rule->offset_minutes);
    }

    private function fromDate(AutoMessageRule $rule, ?Carbon $date): ?Carbon
    {
        if ($date === null || $rule->offset_days === null || $rule->time_of_day === null) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', $rule->time_of_day));

        return $date->copy()
            ->addDays($rule->offset_days)
            ->setTime($hour, $minute);
    }
}
