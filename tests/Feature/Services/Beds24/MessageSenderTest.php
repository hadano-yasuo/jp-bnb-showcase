<?php

namespace Tests\Feature\Services\Beds24;

use App\Models\Message;
use App\Services\Beds24\Exceptions\Beds24Exception;
use App\Services\Beds24\MessageSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MessageSenderTest extends TestCase
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

    private function sourceMessage(): Message
    {
        return Message::create([
            'beds24_message_id' => 500,
            'beds24_booking_id' => 100,
            'beds24_property_id' => 200,
            'beds24_room_id' => 300,
            'source' => 'guest',
            'message' => 'こんにちは',
            'is_read' => false,
            'sent_at' => now()->subMinutes(5),
            'raw_payload' => [],
            'fetched_at' => now()->subMinutes(4),
        ]);
    }

    public function test_reply_posts_with_source_host_and_creates_local_message(): void
    {
        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response(
                [['success' => true, 'new' => ['id' => 999, 'source' => 'host']]],
                201,
            ),
        ]);

        $source = $this->sourceMessage();
        $created = app(MessageSender::class)->replyTo($source, 'ご返信ありがとうございます');

        $this->assertSame(999, $created->beds24_message_id);
        $this->assertSame(100, $created->beds24_booking_id);
        $this->assertSame(200, $created->beds24_property_id);
        $this->assertSame(300, $created->beds24_room_id);
        $this->assertSame(Message::SOURCE_HOST, $created->source);
        $this->assertTrue($created->is_read);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $req->method() === 'POST'
                && is_array($body)
                && $body[0]['bookingId'] === 100
                && $body[0]['source'] === 'host'
                && $body[0]['message'] === 'ご返信ありがとうございます';
        });
    }

    public function test_reply_throws_when_beds24_returns_success_false(): void
    {
        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response(
                [['success' => false, 'errors' => [['action' => 'new message', 'message' => 'could not be saved']]]],
                201,
            ),
        ]);

        $source = $this->sourceMessage();
        $sourceMessageCountBefore = Message::count();

        $this->expectException(Beds24Exception::class);

        try {
            app(MessageSender::class)->replyTo($source, 'test');
        } finally {
            $this->assertSame($sourceMessageCountBefore, Message::count());
        }
    }

    public function test_reply_throws_when_new_id_missing(): void
    {
        Http::fake([
            'https://beds24.example/api/v2/bookings/messages' => Http::response(
                [['success' => true, 'new' => ['source' => 'host']]],
                201,
            ),
        ]);

        $this->expectException(Beds24Exception::class);
        $this->expectExceptionMessage('missing new.id');

        app(MessageSender::class)->replyTo($this->sourceMessage(), 'test');
    }
}
