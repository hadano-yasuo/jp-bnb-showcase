<?php

namespace Tests\Feature\Services\Beds24;

use App\Models\Message;
use App\Services\Beds24\MessageReadSyncer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageReadSyncerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'beds24.api_base' => 'https://beds24.example/api/v2',
            'beds24.refresh_token' => 'test-refresh',
            'beds24.access_token_cache_key' => 'beds24:test:token',
            'beds24.access_token_ttl_seconds' => 3600,
            'beds24.http_timeout' => 5,
        ]);

        Http::fake([
            'https://beds24.example/api/v2/authentication/token' => Http::response(['token' => 'access']),
        ]);
    }

    private function makeMessage(string $source, bool $isRead, int $beds24Id): Message
    {
        return Message::create([
            'beds24_message_id' => $beds24Id,
            'beds24_booking_id' => 100,
            'beds24_property_id' => 200,
            'beds24_room_id' => 300,
            'source' => $source,
            'message' => "msg #{$beds24Id}",
            'is_read' => $isRead,
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
    }

    public function test_empty_input_returns_zeros_without_hitting_api(): void
    {
        $result = app(MessageReadSyncer::class)->markAsRead([]);

        $this->assertSame(['local_updated' => 0, 'beds24_synced' => 0, 'beds24_failed' => 0], $result);
        Http::assertNothingSent();
    }

    public function test_already_read_messages_are_skipped(): void
    {
        $msg = $this->makeMessage('guest', true, 101);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg]);

        $this->assertSame(0, $result['local_updated']);
        Http::assertNothingSent();
    }

    public function test_guest_unread_posts_read_flag_and_updates_local(): void
    {
        $msg = $this->makeMessage('guest', false, 101);

        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response([['success' => true]], 200),
        ]);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg]);

        $this->assertSame(1, $result['local_updated']);
        $this->assertSame(1, $result['beds24_synced']);
        $this->assertSame(0, $result['beds24_failed']);
        $this->assertTrue($msg->fresh()->is_read);

        Http::assertSent(function ($req) {
            return $req->method() === 'POST'
                && $req->data() === [['id' => 101, 'read' => true]];
        });
    }

    public function test_system_unread_updates_local_but_no_beds24_call(): void
    {
        $msg = $this->makeMessage('system', false, 200);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg]);

        $this->assertSame(1, $result['local_updated']);
        $this->assertSame(0, $result['beds24_synced']);
        $this->assertSame(0, $result['beds24_failed']);
        $this->assertTrue($msg->fresh()->is_read);
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_sync_to_beds24_false_skips_beds24_call(): void
    {
        $msg = $this->makeMessage('guest', false, 101);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg], syncToBeds24: false);

        $this->assertSame(1, $result['local_updated']);
        $this->assertSame(0, $result['beds24_synced']);
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_beds24_error_still_updates_local(): void
    {
        $msg = $this->makeMessage('guest', false, 101);

        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response(
                ['success' => false, 'error' => 'server error'],
                500,
            ),
        ]);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg]);

        $this->assertSame(1, $result['local_updated']);
        $this->assertSame(0, $result['beds24_synced']);
        $this->assertSame(1, $result['beds24_failed']);
        $this->assertTrue($msg->fresh()->is_read);
    }

    public function test_partial_per_item_failure_counted(): void
    {
        $msg1 = $this->makeMessage('guest', false, 101);
        $msg2 = $this->makeMessage('guest', false, 102);

        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response([
                ['success' => true],
                ['success' => false, 'errors' => [['message' => 'nope']]],
            ], 200),
        ]);

        $result = app(MessageReadSyncer::class)->markAsRead([$msg1, $msg2]);

        $this->assertSame(2, $result['local_updated']);
        $this->assertSame(1, $result['beds24_synced']);
        $this->assertSame(1, $result['beds24_failed']);
    }
}
