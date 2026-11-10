<?php

namespace Tests\Feature\Http;

use App\Models\AutoMessageRule;
use App\Models\Booking;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Beds24WebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-secret';

    private const ENDPOINT = '/api/beds24/webhook/bookings';

    protected function setUp(): void
    {
        parent::setUp();
        config(['beds24.webhook_secret' => self::SECRET]);

        // BookingUpserter が listing の存在を要求しないよう最小の環境を用意
        Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test',
            'display_name' => '2階',
        ]);
    }

    public function test_rejects_when_secret_header_missing(): void
    {
        $response = $this->postJson(self::ENDPOINT, ['id' => 9001]);

        $response->assertStatus(401);
        $this->assertSame(0, Booking::count());
    }

    public function test_rejects_when_secret_header_wrong(): void
    {
        $response = $this->withHeaders(['X-Webhook-Secret' => 'nope'])
            ->postJson(self::ENDPOINT, ['id' => 9001]);

        $response->assertStatus(401);
        $this->assertSame(0, Booking::count());
    }

    public function test_rejects_when_no_secret_configured(): void
    {
        config(['beds24.webhook_secret' => '']);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'anything'])
            ->postJson(self::ENDPOINT, ['id' => 9001]);

        $response->assertStatus(401);
    }

    public function test_upserts_new_booking_from_single_payload(): void
    {
        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, $this->samplePayload(9001));

        $response->assertOk()->assertJson(['processed' => 1, 'errors' => []]);
        $this->assertDatabaseHas('bookings', [
            'beds24_booking_id' => 9001,
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    }

    public function test_updates_existing_booking(): void
    {
        Booking::create([
            'beds24_booking_id' => 9001,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => 'new',
            'api_source' => 'Airbnb',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'arrival' => '2026-10-01',
            'departure' => '2026-10-02',
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, $this->samplePayload(9001, ['firstName' => 'Updated']));

        $response->assertOk();
        $this->assertSame(1, Booking::count());
        $this->assertSame('Updated', Booking::first()->first_name);
    }

    public function test_accepts_beds24_actual_shape_booking_key(): void
    {
        // beds24 実 payload (2026-09-20 実測): { timeStamp, booking: {...}, infoItems, ... }
        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, [
                'timeStamp' => '2026-09-20T00:39:49Z',
                'booking' => $this->samplePayload(9001),
                'infoItems' => [],
                'invoiceItems' => [],
                'messages' => [],
                'retries' => 0,
            ]);

        $response->assertOk()->assertJson(['processed' => 1, 'errors' => []]);
        $this->assertDatabaseHas('bookings', ['beds24_booking_id' => 9001]);
    }

    public function test_accepts_array_wrapped_payload(): void
    {
        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, [
                'bookings' => [
                    $this->samplePayload(9001),
                    $this->samplePayload(9002),
                ],
            ]);

        $response->assertOk()->assertJson(['processed' => 2]);
        $this->assertSame(2, Booking::count());
    }

    public function test_returns_400_when_payload_has_no_recognizable_shape(): void
    {
        $response = $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, ['unrelated' => 'garbage']);

        $response->assertStatus(400);
    }

    public function test_schedules_auto_message_sends_for_new_booking(): void
    {
        $rule = AutoMessageRule::create([
            'name' => 'test-rule',
            'trigger_event' => AutoMessageRule::EVENT_BOOKING_CREATED,
            'offset_minutes' => 0,
            'channel_scope' => AutoMessageRule::CHANNEL_ALL,
            'body' => 'hi {{ $guest_first_name }}',
            'is_enabled' => true,
            'enabled_from' => '2020-01-01 00:00:00',
        ]);
        $rule->listings()->attach(Listing::first()->id);

        $this->withHeaders(['X-Webhook-Secret' => self::SECRET])
            ->postJson(self::ENDPOINT, $this->samplePayload(9001))
            ->assertOk();

        $this->assertDatabaseHas('auto_message_sends', [
            'auto_message_rule_id' => $rule->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function samplePayload(int $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'propertyId' => 111005,
            'roomId' => 222006,
            'status' => 'confirmed',
            'apiSource' => 'Airbnb',
            'channel' => 'airbnb',
            'firstName' => 'John',
            'lastName' => 'Doe',
            'email' => 'john@example.com',
            'mobile' => null,
            'arrival' => '2026-10-15',
            'departure' => '2026-10-16',
            'numAdult' => 2,
            'numChild' => 0,
            'bookingTime' => '2026-09-19 10:00:00',
            'modifiedTime' => '2026-09-19 10:00:00',
        ], $overrides);
    }
}
