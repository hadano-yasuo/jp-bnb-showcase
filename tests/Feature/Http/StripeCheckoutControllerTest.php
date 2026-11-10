<?php

namespace Tests\Feature\Http;

use App\Models\PendingReservation;
use App\Services\Beds24\Beds24BookingCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class StripeCheckoutControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_dummy';

    protected function setUp(): void
    {
        parent::setUp();
        config(['public_hp.domain' => 'example.test']);
        config(['services.stripe.webhook_secret' => self::SECRET]);
    }

    private function signedPayload(array $payload): array
    {
        $json = json_encode($payload);
        $timestamp = time();
        $signedPayload = $timestamp.'.'.$json;
        $signature = hash_hmac('sha256', $signedPayload, self::SECRET);
        $header = "t={$timestamp},v1={$signature}";

        return [$json, $header];
    }

    private function makePending(): PendingReservation
    {
        return PendingReservation::create([
            'stripe_session_id' => 'cs_test_abc',
            'form_data' => ['email' => 'test@example.com', 'arrival' => '2026-11-01', 'departure' => '2026-11-03'],
            'amount_jpy' => 30000,
            'status' => PendingReservation::STATUS_PENDING,
            'expires_at' => now()->addDay(),
        ]);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        $this->postJson('/stripe/webhook', ['foo' => 'bar'], [
            'Stripe-Signature' => 't=1,v1=invalid',
        ])->assertStatus(400);
    }

    public function test_webhook_returns_500_when_secret_missing(): void
    {
        config(['services.stripe.webhook_secret' => '']);

        $this->postJson('/stripe/webhook', ['foo' => 'bar'], [
            'Stripe-Signature' => 't=1,v1=anything',
        ])->assertStatus(500);
    }

    public function test_webhook_ignores_non_session_completed_events(): void
    {
        $payload = [
            'id' => 'evt_1',
            'object' => 'event',
            'type' => 'payment_intent.created',
            'data' => ['object' => ['id' => 'pi_1']],
        ];
        [$json, $header] = $this->signedPayload($payload);

        $creator = Mockery::mock(Beds24BookingCreator::class);
        $creator->shouldNotReceive('fulfill');
        $this->app->instance(Beds24BookingCreator::class, $creator);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertOk();
    }

    public function test_webhook_fulfills_pending_on_session_completed(): void
    {
        $pending = $this->makePending();

        $payload = [
            'id' => 'evt_2',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => $pending->stripe_session_id,
                    'object' => 'checkout.session',
                    'metadata' => ['pending_reservation_id' => (string) $pending->id],
                ],
            ],
        ];
        [$json, $header] = $this->signedPayload($payload);

        $creator = Mockery::mock(Beds24BookingCreator::class);
        $creator->shouldReceive('fulfill')
            ->once()
            ->withArgs(fn (PendingReservation $p) => $p->id === $pending->id)
            ->andReturn(true);
        $this->app->instance(Beds24BookingCreator::class, $creator);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertOk();
    }

    public function test_webhook_returns_404_when_pending_not_found(): void
    {
        $payload = [
            'id' => 'evt_3',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_missing',
                    'object' => 'checkout.session',
                    'metadata' => ['pending_reservation_id' => '99999'],
                ],
            ],
        ];
        [$json, $header] = $this->signedPayload($payload);

        $this->call('POST', '/stripe/webhook', [], [], [], [
            'HTTP_Stripe-Signature' => $header,
            'CONTENT_TYPE' => 'application/json',
        ], $json)->assertNotFound();
    }

    public function test_success_renders_without_session_id(): void
    {
        $this->get('http://example.test/reserve/success')->assertOk();
    }

    public function test_success_shows_confirmed_message_when_pending_is_paid(): void
    {
        $pending = $this->makePending();
        $pending->update(['status' => PendingReservation::STATUS_PAID]);

        $this->get('http://example.test/reserve/success?session_id=cs_test_abc')
            ->assertOk();
    }
}
