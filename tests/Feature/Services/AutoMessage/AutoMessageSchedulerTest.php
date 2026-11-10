<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Models\AutoMessageRule;
use App\Models\AutoMessageSend;
use App\Models\Booking;
use App\Models\Listing;
use App\Services\AutoMessage\AutoMessageScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutoMessageSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_pending_for_matching_rule(): void
    {
        [$listing, $rule] = $this->makeListingAndRule();
        $booking = $this->makeBooking($listing);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(1, AutoMessageSend::count());
        $send = AutoMessageSend::first();
        $this->assertSame(AutoMessageSend::STATUS_PENDING, $send->status);
        $this->assertSame($rule->id, $send->auto_message_rule_id);
        $this->assertSame($booking->id, $send->booking_id);
        $expected = $booking->arrival->copy()->addDays((int) $rule->offset_days)->setTimeFromTimeString($rule->time_of_day);
        $this->assertSame($expected->toDateTimeString(), $send->scheduled_at->toDateTimeString());
    }

    public function test_does_not_create_non_cancelled_pending_for_cancelled_booking(): void
    {
        [$listing, $rule] = $this->makeListingAndRule();
        $booking = $this->makeBooking($listing, ['status' => Booking::STATUS_CANCELLED]);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_creates_pending_for_cancelled_trigger_when_booking_cancelled(): void
    {
        [$listing, $rule] = $this->makeListingAndRule([
            'trigger_event' => AutoMessageRule::EVENT_CANCELLED,
            'offset_minutes' => 15,
            'offset_days' => null,
            'time_of_day' => null,
        ]);
        $cancelTime = now()->subHour();
        $booking = $this->makeBooking($listing, [
            'status' => Booking::STATUS_CANCELLED,
            'cancel_time' => $cancelTime->toDateTimeString(),
        ]);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(1, AutoMessageSend::count());
        $this->assertSame($cancelTime->copy()->addMinutes(15)->toDateTimeString(), AutoMessageSend::first()->scheduled_at->toDateTimeString());
    }

    public function test_skips_when_channel_mismatch(): void
    {
        [$listing, $rule] = $this->makeListingAndRule(['channel_scope' => AutoMessageRule::CHANNEL_BOOKING]);
        $booking = $this->makeBooking($listing, ['api_source' => 'Airbnb']);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_idempotent_and_updates_scheduled_at_on_arrival_change(): void
    {
        [$listing, $rule] = $this->makeListingAndRule();
        $booking = $this->makeBooking($listing);

        app(AutoMessageScheduler::class)->scheduleFor($booking);
        $this->assertSame(1, AutoMessageSend::count());

        $newArrival = now()->addMonths(2);
        $booking->update(['arrival' => $newArrival->toDateString()]);
        app(AutoMessageScheduler::class)->scheduleFor($booking->fresh());

        $this->assertSame(1, AutoMessageSend::count());
        $expected = $newArrival->copy()->addDays((int) $rule->offset_days)->setTimeFromTimeString($rule->time_of_day);
        $this->assertSame($expected->toDateTimeString(), AutoMessageSend::first()->scheduled_at->toDateTimeString());
    }

    public function test_does_not_create_pending_when_booking_time_before_enabled_from(): void
    {
        [$listing, $rule] = $this->makeListingAndRule(['enabled_from' => '2026-09-01 00:00:00']);
        $booking = $this->makeBooking($listing, ['booking_time' => '2026-08-31 23:59:59']);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_creates_pending_when_booking_time_after_enabled_from(): void
    {
        [$listing, $rule] = $this->makeListingAndRule(['enabled_from' => '2026-09-01 00:00:00']);
        $booking = $this->makeBooking($listing, ['booking_time' => '2026-09-01 00:00:01']);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(1, AutoMessageSend::count());
    }

    public function test_does_not_create_pending_when_rule_enabled_from_null(): void
    {
        [$listing, $rule] = $this->makeListingAndRule(['enabled_from' => null]);
        $booking = $this->makeBooking($listing);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_does_not_create_pending_when_booking_time_null(): void
    {
        [$listing, $rule] = $this->makeListingAndRule([
            'enabled_from' => '2026-09-01 00:00:00',
            'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
            'offset_days' => -3,
            'time_of_day' => '10:00',
            'offset_minutes' => null,
        ]);
        $booking = $this->makeBooking($listing, ['booking_time' => null]);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_does_not_overwrite_non_pending_status(): void
    {
        [$listing, $rule] = $this->makeListingAndRule();
        $booking = $this->makeBooking($listing);

        app(AutoMessageScheduler::class)->scheduleFor($booking);
        $send = AutoMessageSend::first();
        $send->update(['status' => AutoMessageSend::STATUS_DRY_RUN, 'rendered_body' => 'preserved']);

        $booking->update(['arrival' => now()->addMonths(2)->toDateString()]);
        app(AutoMessageScheduler::class)->scheduleFor($booking->fresh());

        $send->refresh();
        $this->assertSame(AutoMessageSend::STATUS_DRY_RUN, $send->status);
        $this->assertSame('preserved', $send->rendered_body);
    }

    public function test_does_not_create_pending_when_checkin_scheduled_at_already_past(): void
    {
        // checkin -3日 10:00 で arrival が今日 → scheduled_at は 3日前の過去 → 作らない
        [$listing, $rule] = $this->makeListingAndRule();
        $booking = $this->makeBooking($listing, [
            'arrival' => now()->toDateString(), // today
            'departure' => now()->addDay()->toDateString(),
        ]);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(0, AutoMessageSend::count());
    }

    public function test_creates_pending_when_booking_created_scheduled_at_in_past(): void
    {
        // booking_created は過去 scheduled_at でも作る (予約イベント基点で即発火が正)
        [$listing, $rule] = $this->makeListingAndRule([
            'trigger_event' => AutoMessageRule::EVENT_BOOKING_CREATED,
            'offset_minutes' => 0,
            'offset_days' => null,
            'time_of_day' => null,
        ]);
        $booking = $this->makeBooking($listing, [
            'booking_time' => now()->subDays(5)->toDateTimeString(),
        ]);

        app(AutoMessageScheduler::class)->scheduleFor($booking);

        $this->assertSame(1, AutoMessageSend::count());
    }

    /**
     * @param  array<string, mixed>  $ruleOverrides
     * @return array{0: Listing, 1: AutoMessageRule}
     */
    private function makeListingAndRule(array $ruleOverrides = []): array
    {
        $listing = Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test',
            'display_name' => 'Building B 2階',
        ]);

        $rule = AutoMessageRule::create(array_merge([
            'name' => 'checkin-3days',
            'trigger_event' => AutoMessageRule::EVENT_CHECKIN,
            'offset_days' => -3,
            'time_of_day' => '10:00',
            'channel_scope' => AutoMessageRule::CHANNEL_AIRBNB,
            'body' => 'test body',
            'is_enabled' => false,
            'enabled_from' => '2020-01-01 00:00:00',
        ], $ruleOverrides));

        $rule->listings()->attach($listing->id);

        return [$listing, $rule];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBooking(Listing $listing, array $overrides = []): Booking
    {
        // arrival はテスト実行時刻からの相対で未来に置く (scheduler の past-skip 対策)
        $arrival = now()->addMonth();

        return Booking::create(array_merge([
            'beds24_booking_id' => 1000 + random_int(1, 999999),
            'beds24_property_id' => $listing->beds24_property_id,
            'beds24_room_id' => $listing->beds24_room_id,
            'status' => Booking::STATUS_CONFIRMED,
            'api_source' => 'Airbnb',
            'arrival' => $arrival->toDateString(),
            'departure' => $arrival->copy()->addDays(3)->toDateString(),
            'booking_time' => $arrival->copy()->subDays(7)->toDateTimeString(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ], $overrides));
    }
}
