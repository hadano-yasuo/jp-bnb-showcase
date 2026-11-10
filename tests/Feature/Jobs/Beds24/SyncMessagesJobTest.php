<?php

namespace Tests\Feature\Jobs\Beds24;

use App\Jobs\AI\TranslateGuestMessageJob;
use App\Jobs\Beds24\SyncMessagesJob;
use App\Models\Beds24Credential;
use App\Models\Beds24SyncLog;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncMessagesJobTest extends TestCase
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

        Beds24Credential::create([
            'name' => 'self',
            'refresh_token' => 'test-refresh',
            'scopes' => [],
            'is_active' => true,
        ]);

        Http::fake([
            'https://beds24.example/api/v2/authentication/token' => Http::response(['token' => 'access']),
        ]);

        // guest メッセージ作成時に TranslateGuestMessageJob が dispatch されるが、
        // テスト対象は sync ロジックなので queue をfake（Claude API を叩かない）
        Queue::fake([TranslateGuestMessageJob::class]);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    private function fakeMessagesPage(array $data, bool $hasNext = false, ?string $nextLink = null): array
    {
        return [
            'success' => true,
            'type' => 'message',
            'count' => count($data),
            'pages' => ['nextPageExists' => $hasNext, 'nextPageLink' => $nextLink],
            'data' => $data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeMessage(int $id, int $bookingId = 100, string $source = 'guest'): array
    {
        return [
            'id' => $id,
            'bookingId' => $bookingId,
            'propertyId' => 200,
            'roomId' => 300,
            'source' => $source,
            'time' => '2026-08-26T14:47:27Z',
            'message' => "msg #{$id}",
            'read' => true,
        ];
    }

    public function test_creates_new_messages_and_records_success_log(): void
    {
        Http::fake([
            'https://beds24.example/api/v2/authentication/token' => Http::response(['token' => 'access']),
            'https://beds24.example/api/v2/bookings/messages*' => Http::response(
                $this->fakeMessagesPage([
                    $this->fakeMessage(101),
                    $this->fakeMessage(100),
                ])
            ),
        ]);

        SyncMessagesJob::dispatchSync();

        $this->assertSame(2, Message::count());
        $this->assertNotNull(Message::where('beds24_message_id', 101)->first());

        $log = Beds24SyncLog::latest('id')->firstOrFail();
        $this->assertSame(Beds24SyncLog::STATUS_SUCCESS, $log->status);
        $this->assertSame(2, $log->records_fetched);
        $this->assertSame(2, $log->records_created);
        $this->assertSame(1, $log->pages_fetched);
    }

    public function test_stops_at_known_message_within_page(): void
    {
        Message::create([
            'beds24_message_id' => 99,
            'beds24_booking_id' => 100,
            'beds24_property_id' => 200,
            'beds24_room_id' => 300,
            'source' => 'host',
            'message' => 'old',
            'is_read' => true,
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        Http::fake([
            'https://beds24.example/api/v2/authentication/token' => Http::response(['token' => 'access']),
            'https://beds24.example/api/v2/bookings/messages*' => Http::response(
                $this->fakeMessagesPage([
                    $this->fakeMessage(102),
                    $this->fakeMessage(101),
                    $this->fakeMessage(99),
                    $this->fakeMessage(98),
                ])
            ),
        ]);

        SyncMessagesJob::dispatchSync();

        $this->assertSame(3, Message::count());
        $this->assertNotNull(Message::where('beds24_message_id', 101)->first());
        $this->assertNull(Message::where('beds24_message_id', 98)->first());

        $log = Beds24SyncLog::latest('id')->firstOrFail();
        $this->assertSame(2, $log->records_created);
        $this->assertSame(3, $log->records_fetched);
    }

    public function test_follows_next_page_when_all_new(): void
    {
        $page2 = 'https://beds24.example/api/v2/bookings/messages?page=2';

        Http::fakeSequence('https://beds24.example/api/v2/bookings/messages*')
            ->push($this->fakeMessagesPage(
                [$this->fakeMessage(200)],
                hasNext: true,
                nextLink: $page2,
            ))
            ->push($this->fakeMessagesPage([$this->fakeMessage(199)]));

        SyncMessagesJob::dispatchSync();

        $this->assertSame(2, Message::count());
        $log = Beds24SyncLog::latest('id')->firstOrFail();
        $this->assertSame(2, $log->pages_fetched);
        $this->assertSame(2, $log->records_created);
    }

    public function test_records_failure_when_client_throws(): void
    {
        Http::fake([
            'https://beds24.example/api/v2/authentication/token' => Http::response(['token' => 'access']),
            'https://beds24.example/api/v2/bookings/messages*' => Http::response(
                ['success' => false, 'error' => 'no scope'],
                500
            ),
        ]);

        try {
            SyncMessagesJob::dispatchSync();
            $this->fail('Expected exception');
        } catch (\Throwable) {
            // 期待通り
        }

        $log = Beds24SyncLog::latest('id')->firstOrFail();
        $this->assertSame(Beds24SyncLog::STATUS_FAILURE, $log->status);
        $this->assertNotNull($log->error_message);
    }

    public function test_throws_when_credential_missing(): void
    {
        Beds24Credential::query()->delete();

        $this->expectException(\RuntimeException::class);
        SyncMessagesJob::dispatchSync();
    }
}
