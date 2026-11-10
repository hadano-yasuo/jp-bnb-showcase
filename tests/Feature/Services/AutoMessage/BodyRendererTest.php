<?php

namespace Tests\Feature\Services\AutoMessage;

use App\Models\Booking;
use App\Models\Listing;
use App\Services\AutoMessage\BodyRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BodyRendererTest extends TestCase
{
    use RefreshDatabase;

    public function test_renders_guest_and_dates(): void
    {
        $booking = $this->makeBooking();

        $rendered = app(BodyRenderer::class)->render(
            '{{ $guest_first_name }} 様、{{ $arrival }} にご到着予定ですね。',
            $booking,
        );

        $this->assertSame('John 様、2026年9月20日 にご到着予定ですね。', $rendered);
    }

    public function test_renders_dates_in_english_when_locale_is_en(): void
    {
        $booking = $this->makeBooking();

        $rendered = app(BodyRenderer::class)->render(
            'Arriving on {{ $arrival }}, checking out {{ $departure }}.',
            $booking,
            'en',
        );

        $this->assertSame('Arriving on September 20, 2026, checking out September 23, 2026.', $rendered);
    }

    public function test_renders_guest_registration_url(): void
    {
        $booking = $this->makeBooking();

        $rendered = app(BodyRenderer::class)->render(
            '登録は {{ $guest_registration_url }} から',
            $booking,
        );

        $this->assertMatchesRegularExpression('#登録は https?://[^\s]+/register/[A-Za-z0-9]{48} から#', $rendered);
    }

    public function test_renders_listing_wifi_password(): void
    {
        $listing = Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test-room',
            'display_name' => 'Building B 2階',
            'wifi_password' => 'super-secret-wifi',
        ]);
        $booking = $this->makeBooking(['beds24_room_id' => $listing->beds24_room_id]);

        $rendered = app(BodyRenderer::class)->render(
            'Wi-Fi: {{ $listing->wifi_password }}',
            $booking->fresh('listing'),
        );

        $this->assertSame('Wi-Fi: super-secret-wifi', $rendered);
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
            'arrival' => '2026-09-20',
            'departure' => '2026-09-23',
            'num_adult' => 2,
            'raw_payload' => [],
            'fetched_at' => now(),
        ], $overrides));
    }
}
