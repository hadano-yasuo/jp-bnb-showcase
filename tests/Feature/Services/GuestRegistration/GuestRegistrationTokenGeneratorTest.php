<?php

namespace Tests\Feature\Services\GuestRegistration;

use App\Models\Booking;
use App\Models\GuestRegistrationToken;
use App\Services\GuestRegistration\GuestRegistrationTokenGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestRegistrationTokenGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_token_for_future_booking(): void
    {
        $booking = $this->makeBooking();

        $token = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertNotNull($token);
        $this->assertSame($booking->id, $token->booking_id);
        $this->assertSame(48, strlen($token->token));
        // departure + 7 日
        $this->assertTrue(
            $token->expires_at->toDateString() === $booking->departure->copy()->addDays(7)->toDateString(),
        );
    }

    public function test_is_idempotent(): void
    {
        $booking = $this->makeBooking();

        $t1 = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);
        $t2 = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertSame($t1->id, $t2->id);
        $this->assertSame(1, GuestRegistrationToken::count());
    }

    public function test_skips_cancelled_bookings(): void
    {
        $booking = $this->makeBooking(['status' => Booking::STATUS_CANCELLED]);

        $token = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertNull($token);
        $this->assertSame(0, GuestRegistrationToken::count());
    }

    public function test_skips_black_status(): void
    {
        $booking = $this->makeBooking(['status' => Booking::STATUS_BLACK]);

        $token = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertNull($token);
    }

    public function test_skips_old_bookings_departed_more_than_30_days_ago(): void
    {
        $booking = $this->makeBooking([
            'arrival' => now()->subDays(90)->toDateString(),
            'departure' => now()->subDays(60)->toDateString(),
        ]);

        $token = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertNull($token);
    }

    public function test_creates_token_for_recent_past_booking_within_30_days(): void
    {
        // departure が 10 日前は許容
        $booking = $this->makeBooking([
            'arrival' => now()->subDays(15)->toDateString(),
            'departure' => now()->subDays(10)->toDateString(),
        ]);

        $token = app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        $this->assertNotNull($token);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeBooking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'beds24_booking_id' => 1000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'arrival' => now()->addDay()->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
            'num_adult' => 2,
            'raw_payload' => [],
            'fetched_at' => now(),
        ], $overrides));
    }
}
