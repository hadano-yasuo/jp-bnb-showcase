<?php

namespace Tests\Feature\Models;

use App\Models\Booking;
use App\Models\GuestRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Booking::isRegistrationIncomplete() の 3 条件 (未登録 / 人数不足 / 非日本人 id 欠落) を検証。
 * AutoMessageRule::CONDITION_REGISTRATION_INCOMPLETE の発火判断に使われる。
 */
class BookingRegistrationIncompleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_when_no_registration(): void
    {
        $booking = $this->makeBooking(numAdult: 2);

        $this->assertTrue($booking->isRegistrationIncomplete());
    }

    public function test_incomplete_when_registration_count_less_than_party(): void
    {
        $booking = $this->makeBooking(numAdult: 2);
        $this->addRegistration($booking, guestNumber: 1, nationality: 'JP');

        $this->assertTrue($booking->refresh()->isRegistrationIncomplete());
    }

    public function test_complete_when_all_japanese_registered(): void
    {
        $booking = $this->makeBooking(numAdult: 2);
        $this->addRegistration($booking, guestNumber: 1, nationality: 'JP');
        $this->addRegistration($booking, guestNumber: 2, nationality: 'JP');

        $this->assertFalse($booking->refresh()->isRegistrationIncomplete());
    }

    public function test_incomplete_when_foreign_id_verdict_not_ok(): void
    {
        $booking = $this->makeBooking(numAdult: 1);
        $this->addRegistration(
            $booking,
            guestNumber: 1,
            nationality: 'US',
            idImageVerdict: GuestRegistration::VERDICT_PENDING,
        );

        $this->assertTrue($booking->refresh()->isRegistrationIncomplete());
    }

    public function test_complete_when_foreign_id_verdict_ok(): void
    {
        $booking = $this->makeBooking(numAdult: 1);
        $this->addRegistration(
            $booking,
            guestNumber: 1,
            nationality: 'US',
            idImageVerdict: GuestRegistration::VERDICT_OK,
        );

        $this->assertFalse($booking->refresh()->isRegistrationIncomplete());
    }

    public function test_complete_when_foreign_admin_overrides_ai_verdict(): void
    {
        // AI 判定 pending でも admin_verdict=OK なら effectiveVerdict=OK で通す
        $booking = $this->makeBooking(numAdult: 1);
        $this->addRegistration(
            $booking,
            guestNumber: 1,
            nationality: 'FR',
            idImageVerdict: GuestRegistration::VERDICT_PENDING,
            adminVerdict: GuestRegistration::VERDICT_OK,
        );

        $this->assertFalse($booking->refresh()->isRegistrationIncomplete());
    }

    public function test_incomplete_when_party_size_zero(): void
    {
        // num_adult も num_child も null は「人数不明」の予約 → 安全側で未完了扱い
        $booking = $this->makeBooking(numAdult: null, numChild: null);

        $this->assertTrue($booking->isRegistrationIncomplete());
    }

    private function makeBooking(?int $numAdult = 1, ?int $numChild = null): Booking
    {
        return Booking::create([
            'beds24_booking_id' => 2000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'api_source' => 'Airbnb',
            'first_name' => 'Test',
            'last_name' => 'Guest',
            'arrival' => '2026-10-01',
            'departure' => '2026-10-03',
            'num_adult' => $numAdult,
            'num_child' => $numChild,
            'booking_time' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
    }

    private function addRegistration(
        Booking $booking,
        int $guestNumber,
        string $nationality,
        ?string $idImageVerdict = null,
        ?string $adminVerdict = null,
    ): GuestRegistration {
        return GuestRegistration::create([
            'booking_id' => $booking->id,
            'guest_number' => $guestNumber,
            'name' => 'Guest '.$guestNumber,
            'address' => 'Test Address',
            'nationality' => $nationality,
            'is_representative' => $guestNumber === 1,
            'id_image_verdict' => $idImageVerdict,
            'admin_verdict' => $adminVerdict,
            'submitted_at' => now(),
        ]);
    }
}
