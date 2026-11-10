<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Services\GuestIdImage\GuestIdImageDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GuestIdImageDuplicateDetectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_detects_sha256_exact_match(): void
    {
        $booking1 = $this->createBooking();
        $booking2 = $this->createBooking();

        $hash = str_repeat('a', 64);
        $r1 = GuestRegistration::create($this->registrationPayload($booking1, [
            'id_image_sha256' => $hash,
        ]));
        $r2 = GuestRegistration::create($this->registrationPayload($booking2, [
            'id_image_sha256' => $hash,
            'guest_number' => 1,
        ]));

        $results = app(GuestIdImageDuplicateDetector::class)->detect($r2);

        $this->assertCount(1, $results);
        $this->assertSame('exact', $results[0]['type']);
        $this->assertSame($r1->id, $results[0]['registration']->id);
    }

    public function test_detects_ai_name_match_when_sha256_differs(): void
    {
        $booking1 = $this->createBooking();
        $booking2 = $this->createBooking();

        $r1 = GuestRegistration::create($this->registrationPayload($booking1, [
            'id_image_sha256' => str_repeat('a', 64),
            'id_image_ai_extracted' => ['extracted_name' => 'TANAKA TARO'],
        ]));
        $r2 = GuestRegistration::create($this->registrationPayload($booking2, [
            'id_image_sha256' => str_repeat('b', 64),
            'id_image_ai_extracted' => ['extracted_name' => 'tanaka taro '],
            'guest_number' => 1,
        ]));

        $results = app(GuestIdImageDuplicateDetector::class)->detect($r2);

        $this->assertCount(1, $results);
        $this->assertSame('name_match', $results[0]['type']);
        $this->assertSame($r1->id, $results[0]['registration']->id);
    }

    public function test_no_duplicate_when_hashes_and_names_differ(): void
    {
        $booking1 = $this->createBooking();
        $booking2 = $this->createBooking();

        GuestRegistration::create($this->registrationPayload($booking1, [
            'id_image_sha256' => str_repeat('a', 64),
            'id_image_ai_extracted' => ['extracted_name' => 'Alice'],
        ]));
        $r2 = GuestRegistration::create($this->registrationPayload($booking2, [
            'id_image_sha256' => str_repeat('b', 64),
            'id_image_ai_extracted' => ['extracted_name' => 'Bob'],
            'guest_number' => 1,
        ]));

        $results = app(GuestIdImageDuplicateDetector::class)->detect($r2);

        $this->assertSame([], $results);
    }

    public function test_ignores_bookings_older_than_180_days(): void
    {
        // 200 日以上前の予約は対象外
        $oldBooking = $this->createBooking(['arrival' => now()->subDays(200)->toDateString()]);
        $newBooking = $this->createBooking();

        $hash = str_repeat('c', 64);
        GuestRegistration::create($this->registrationPayload($oldBooking, [
            'id_image_sha256' => $hash,
        ]));
        $r2 = GuestRegistration::create($this->registrationPayload($newBooking, [
            'id_image_sha256' => $hash,
            'guest_number' => 1,
        ]));

        $results = app(GuestIdImageDuplicateDetector::class)->detect($r2);

        $this->assertSame([], $results);
    }

    public function test_excludes_self_from_candidates(): void
    {
        $booking = $this->createBooking();
        $r = GuestRegistration::create($this->registrationPayload($booking, [
            'id_image_sha256' => str_repeat('d', 64),
        ]));

        $results = app(GuestIdImageDuplicateDetector::class)->detect($r);

        $this->assertSame([], $results);
    }

    /**
     * @param  array<string, mixed>  $attrs
     * @return array<string, mixed>
     */
    private function registrationPayload(Booking $booking, array $attrs = []): array
    {
        return array_merge([
            'booking_id' => $booking->id,
            'guest_number' => 1,
            'name' => 'Guest',
            'address' => 'Somewhere',
            'age' => 30,
            'nationality' => 'US',
            'sex' => 'male',
            'is_representative' => true,
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'id_image_path' => 'test-path.webp',
            'id_image_compressed' => false,
            'submitted_at' => now(),
        ], $attrs);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createBooking(array $overrides = []): Booking
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
