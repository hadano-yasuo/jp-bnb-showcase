<?php

namespace App\Services\Calendar;

use Illuminate\Support\Carbon;
use Yasumi\Yasumi;

class JapanHolidayProvider
{
    /**
     * 期間内の日本の祝日を [YYYY-MM-DD => 祝日名] で返す。
     * 振替休日と国民の休日も含む（Yasumi の Japan provider が計算）。
     *
     * @return array<string, string>
     */
    public function between(Carbon $from, Carbon $to): array
    {
        $result = [];
        for ($year = (int) $from->year; $year <= (int) $to->year; $year++) {
            $holidays = Yasumi::create('Japan', $year, 'ja_JP');
            foreach ($holidays->getHolidays() as $holiday) {
                $date = $holiday->format('Y-m-d');
                if ($date < $from->toDateString() || $date > $to->toDateString()) {
                    continue;
                }
                $result[$date] = (string) $holiday->getName();
            }
        }

        return $result;
    }
}
