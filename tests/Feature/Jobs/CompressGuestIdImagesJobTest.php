<?php

namespace Tests\Feature\Jobs;

use App\Jobs\CompressGuestIdImagesJob;
use App\Models\Booking;
use App\Models\GuestRegistration;
use App\Services\GuestIdImage\GuestIdImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CompressGuestIdImagesJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_compresses_registrations_older_than_7_days(): void
    {
        $recent = $this->makeRegistration(departure: now()->subDays(3)->toDateString());
        $old = $this->makeRegistration(departure: now()->subDays(10)->toDateString());
        $veryOld = $this->makeRegistration(departure: now()->subDays(60)->toDateString());

        $store = Mockery::mock(GuestIdImageStore::class);
        // 対象は old + veryOld のみ（recent は departure+7 に達していない）
        $store->shouldReceive('compress')->twice()->withAnyArgs();

        (new CompressGuestIdImagesJob)->handle($store);

        $this->assertFalse($recent->fresh()->id_image_compressed);
        $this->assertTrue($old->fresh()->id_image_compressed);
        $this->assertTrue($veryOld->fresh()->id_image_compressed);
    }

    public function test_skips_registrations_already_compressed(): void
    {
        $already = $this->makeRegistration(
            departure: now()->subDays(30)->toDateString(),
            compressed: true,
        );

        $store = Mockery::mock(GuestIdImageStore::class);
        $store->shouldNotReceive('compress');

        (new CompressGuestIdImagesJob)->handle($store);

        $this->assertTrue($already->fresh()->id_image_compressed);
    }

    public function test_skips_registrations_without_image(): void
    {
        $noImage = $this->makeRegistration(
            departure: now()->subDays(30)->toDateString(),
            imagePath: null,
        );

        $store = Mockery::mock(GuestIdImageStore::class);
        $store->shouldNotReceive('compress');

        (new CompressGuestIdImagesJob)->handle($store);

        // id_image_compressed は default false のまま
        $this->assertFalse($noImage->fresh()->id_image_compressed);
    }

    public function test_continues_when_compress_throws_for_one_registration(): void
    {
        $a = $this->makeRegistration(departure: now()->subDays(30)->toDateString());
        $b = $this->makeRegistration(departure: now()->subDays(30)->toDateString());

        $store = Mockery::mock(GuestIdImageStore::class);
        // a は例外、b は成功
        $store->shouldReceive('compress')
            ->once()
            ->with($a->id_image_path)
            ->andThrow(new \RuntimeException('storage error'));
        $store->shouldReceive('compress')
            ->once()
            ->with($b->id_image_path)
            ->andReturnNull();

        (new CompressGuestIdImagesJob)->handle($store);

        // a は失敗のため未圧縮のまま、b は圧縮済み
        $this->assertFalse($a->fresh()->id_image_compressed);
        $this->assertTrue($b->fresh()->id_image_compressed);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function makeRegistration(string $departure, bool $compressed = false, ?string $imagePath = 'guest-ids/test.webp'): GuestRegistration
    {
        $booking = Booking::create([
            'beds24_booking_id' => 1000 + random_int(1, 999999),
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'arrival' => now()->subDays(20)->toDateString(),
            'departure' => $departure,
            'num_adult' => 2,
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        return GuestRegistration::create([
            'booking_id' => $booking->id,
            'guest_number' => 1,
            'name' => 'Guest',
            'address' => 'Somewhere',
            'age' => 30,
            'nationality' => 'Japan',
            'sex' => 'male',
            'is_representative' => true,
            'previous_stay' => 'Home',
            'next_destination' => 'Osaka',
            'id_image_path' => $imagePath,
            'id_image_compressed' => $compressed,
            'submitted_at' => now(),
        ]);
    }
}
