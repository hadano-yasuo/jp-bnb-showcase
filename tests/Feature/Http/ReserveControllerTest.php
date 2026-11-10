<?php

namespace Tests\Feature\Http;

use App\Models\Booking;
use App\Models\GuestChannel;
use App\Models\Listing;
use App\Models\PendingReservation;
use App\Services\Beds24\Beds24PriceFetcher;
use App\Services\Stripe\StripeCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Stripe\Checkout\Session as StripeSession;
use Tests\TestCase;

/**
 * Phase 1 Stripe Checkout 直接予約フロー:
 *   POST /reserve/direct → バリデーション + 価格取得 + PendingReservation 作成
 *   → Stripe Checkout Session 作成 → ゲストを stripe.com へリダイレクト。
 *
 * 価格取得と Stripe Session 作成はテスト中モックする。
 */
class ReserveControllerTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_CHECKOUT_URL = 'https://checkout.stripe.com/pay/cs_test_123';

    protected function setUp(): void
    {
        parent::setUp();
        config(['public_hp.domain' => 'example.test']);

        // 2 部屋ぶんの Listing を seed (propertyId resolve 用)
        Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test',
            'display_name' => 'Building B2階',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'room_slug' => 'sample-location-2f',
            'arrival' => now()->addDays(10)->toDateString(),
            'departure' => now()->addDays(12)->toDateString(),
            'num_adult' => 2,
            'num_child' => 0,
            'name' => '山田 太郎',
            'email' => 'yamada@example.com',
            'phone' => '090-0000-0000',
            'notes' => 'よろしくお願いします',
            'policy_agreed' => '1',
            'website' => '',
            'form_ts' => Crypt::encryptString((string) (time() - 5)),
        ], $overrides);
    }

    private function mockPriceAndCheckout(int $priceJpy = 30000): void
    {
        $fetcher = Mockery::mock(Beds24PriceFetcher::class);
        $fetcher->shouldReceive('totalPrice')->andReturn($priceJpy);
        $this->app->instance(Beds24PriceFetcher::class, $fetcher);

        // Stripe Session は static メソッドが絡むため実インスタンスを使う
        $session = StripeSession::constructFrom([
            'id' => 'cs_test_123',
            'url' => self::STRIPE_CHECKOUT_URL,
        ]);

        $checkout = Mockery::mock(StripeCheckoutService::class);
        $checkout->shouldReceive('createSession')->andReturn($session);
        $this->app->instance(StripeCheckoutService::class, $checkout);
    }

    public function test_reserve_ota_landing_renders(): void
    {
        $response = $this->get('http://example.test/reserve');
        $response->assertOk();
        $response->assertSee('airbnb.jp', false);
        $response->assertSee('booking.com', false);
    }

    public function test_show_renders_form_without_basic_auth(): void
    {
        $this->get('http://example.test/reserve/direct')->assertOk();
    }

    public function test_store_creates_pending_reservation_and_redirects_to_stripe(): void
    {
        $this->mockPriceAndCheckout(30000);

        $this->post('http://example.test/reserve/direct', $this->validPayload())
            ->assertRedirect(self::STRIPE_CHECKOUT_URL);

        $this->assertSame(1, PendingReservation::count());
        $pending = PendingReservation::first();
        $this->assertSame(PendingReservation::STATUS_PENDING, $pending->status);
        $this->assertSame(30000, $pending->amount_jpy);
        $this->assertSame('cs_test_123', $pending->stripe_session_id);
        $this->assertSame('yamada@example.com', $pending->form_data['email']);
    }

    public function test_store_requires_policy_agreement(): void
    {
        $this->mockPriceAndCheckout();

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'policy_agreed' => null,
        ]))->assertSessionHasErrors('policy_agreed');

        $this->assertSame(0, PendingReservation::count());
    }

    public function test_store_continues_existing_channel_when_inquiry_token_given(): void
    {
        $this->mockPriceAndCheckout();

        $inquiry = GuestChannel::create([
            'token' => GuestChannel::generateToken(),
            'guest_email' => 'yamada@example.com',
            'guest_locale' => 'ja',
            'expires_at' => now()->addDays(90),
        ]);

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'inquiry_token' => $inquiry->token,
        ]))->assertRedirect(self::STRIPE_CHECKOUT_URL);

        $pending = PendingReservation::first();
        $this->assertSame($inquiry->id, $pending->guest_channel_id);
    }

    public function test_store_validates_dates(): void
    {
        $this->mockPriceAndCheckout();

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'arrival' => now()->addDays(5)->toDateString(),
            'departure' => now()->addDays(3)->toDateString(),
        ]))->assertSessionHasErrors('departure');
    }

    public function test_store_rejects_unknown_room_slug(): void
    {
        $this->mockPriceAndCheckout();

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'room_slug' => 'unknown-room',
        ]))->assertSessionHasErrors('room_slug');
    }

    public function test_store_rejects_building_slug_in_phase1(): void
    {
        $this->mockPriceAndCheckout();

        $this->from('http://example.test/reserve/direct')
            ->post('http://example.test/reserve/direct', $this->validPayload([
                'room_slug' => '_building:other-location:3',
                'num_adult' => 10,
            ]))
            ->assertRedirect('http://example.test/reserve/direct')
            ->assertSessionHasErrors('room_slug');

        $this->assertSame(0, PendingReservation::count());
    }

    public function test_store_rejects_honeypot_submission(): void
    {
        $this->mockPriceAndCheckout();

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'website' => 'https://spam.example.com',
        ]))->assertStatus(422);

        $this->assertSame(0, PendingReservation::count());
    }

    public function test_store_rejects_too_fast_submission(): void
    {
        $this->mockPriceAndCheckout();

        $this->post('http://example.test/reserve/direct', $this->validPayload([
            'form_ts' => Crypt::encryptString((string) time()),
        ]))->assertStatus(422);

        $this->assertSame(0, PendingReservation::count());
    }

    public function test_store_rejects_overlapping_booking(): void
    {
        $this->mockPriceAndCheckout();

        Booking::create([
            'beds24_booking_id' => 999_991,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => 'confirmed',
            'arrival' => now()->addDays(10)->toDateString(),
            'departure' => now()->addDays(12)->toDateString(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        $this->from('http://example.test/reserve/direct')
            ->post('http://example.test/reserve/direct', $this->validPayload())
            ->assertRedirect('http://example.test/reserve/direct')
            ->assertSessionHasErrors('arrival');

        $this->assertSame(0, PendingReservation::count());
    }

    public function test_store_allows_booking_same_day_checkout_as_new_checkin(): void
    {
        $this->mockPriceAndCheckout();

        // 既存予約: 5日後〜10日後 (departure=10日後 = 退出日)
        Booking::create([
            'beds24_booking_id' => 999_992,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => 'confirmed',
            'arrival' => now()->addDays(5)->toDateString(),
            'departure' => now()->addDays(10)->toDateString(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        $this->post('http://example.test/reserve/direct', $this->validPayload())
            ->assertRedirect(self::STRIPE_CHECKOUT_URL);
    }

    public function test_store_ignores_cancelled_booking_for_overlap_check(): void
    {
        $this->mockPriceAndCheckout();

        Booking::create([
            'beds24_booking_id' => 999_993,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => 'cancelled',
            'arrival' => now()->addDays(10)->toDateString(),
            'departure' => now()->addDays(12)->toDateString(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        $this->post('http://example.test/reserve/direct', $this->validPayload())
            ->assertRedirect(self::STRIPE_CHECKOUT_URL);
    }

    public function test_store_shows_error_when_price_fetch_fails(): void
    {
        $fetcher = Mockery::mock(Beds24PriceFetcher::class);
        $fetcher->shouldReceive('totalPrice')->andThrow(new \RuntimeException('beds24 500'));
        $this->app->instance(Beds24PriceFetcher::class, $fetcher);

        $this->from('http://example.test/reserve/direct')
            ->post('http://example.test/reserve/direct', $this->validPayload())
            ->assertRedirect('http://example.test/reserve/direct')
            ->assertSessionHasErrors('arrival');
    }
}
