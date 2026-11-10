<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\Booking;
use App\Services\AutoMessage\ScheduledAtCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ScheduledAtCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_booking_created_offset_minutes(): void
    {
        $rule = new AutoMessageRule([
            'trigger_event' => AutoMessageRule::EVENT_BOOKING_CREATED,
            'offset_minutes' => 60,
        ]);

        $booking = new Booking(['booking_time' => Carbon::parse('2026-09-13 10:00:00')]);

        $result = app(ScheduledAtCalculator::class)->calculate($rule, $booking);

        $this->assertSame('2026-09-13 11:00:00', $result?->toDateTimeString());
    }

    public function test_checkin_offset_days_plus_time_of_day(): void
    {
        $rule = new AutoMessageRule([
            'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
            'offset_days' => -3,
            'time_of_day' => '10:00',
        ]);

        $booking = new Booking(['arrival' => Carbon::parse('2026-09-20')]);

        $result = app(ScheduledAtCalculator::class)->calculate($rule, $booking);

        $this->assertSame('2026-09-17 10:00:00', $result?->toDateTimeString());
    }

    public function test_checkout_positive_offset(): void
    {
        $rule = new AutoMessageRule([
            'trigger_event' => AutoMessageRule::EVENT_CHECKOUT,
            'offset_days' => 1,
            'time_of_day' => '09:30',
        ]);

        $booking = new Booking(['departure' => Carbon::parse('2026-09-20')]);

        $result = app(ScheduledAtCalculator::class)->calculate($rule, $booking);

        $this->assertSame('2026-09-21 09:30:00', $result?->toDateTimeString());
    }

    public function test_cancelled_offset_minutes_from_cancel_time(): void
    {
        $rule = new AutoMessageRule([
            'trigger_event' => AutoMessageRule::EVENT_CANCELLED,
            'offset_minutes' => 15,
        ]);

        $booking = new Booking(['cancel_time' => Carbon::parse('2026-09-14 12:00:00')]);

        $result = app(ScheduledAtCalculator::class)->calculate($rule, $booking);

        $this->assertSame('2026-09-14 12:15:00', $result?->toDateTimeString());
    }

    public function test_returns_null_when_source_field_missing(): void
    {
        $rule = new AutoMessageRule([
            'trigger_event' => AutoMessageRule::EVENT_BOOKING_CREATED,
            'offset_minutes' => 60,
        ]);

        $result = app(ScheduledAtCalculator::class)->calculate($rule, new Booking);

        $this->assertNull($result);
    }
}
