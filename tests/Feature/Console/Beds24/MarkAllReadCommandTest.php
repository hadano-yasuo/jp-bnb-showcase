<?php

namespace Tests\Feature\Console\Beds24;

use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarkAllReadCommandTest extends TestCase
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
            'https://beds24.example/api/v2/bookings/messages' => Http::response([['success' => true], ['success' => true]], 200),
        ]);
    }

    private function seedUnread(int $count, string $source = 'guest'): void
    {
        for ($i = 1; $i <= $count; $i++) {
            Message::create([
                'beds24_message_id' => 1000 + $i,
                'beds24_booking_id' => 100,
                'beds24_property_id' => 200,
                'beds24_room_id' => 300,
                'source' => $source,
                'message' => "msg #{$i}",
                'is_read' => false,
                'sent_at' => now(),
                'raw_payload' => [],
                'fetched_at' => now(),
            ]);
        }
    }

    public function test_dry_run_reports_count_and_does_not_update(): void
    {
        $this->seedUnread(2);

        $this->artisan('beds24:mark-all-read', ['--dry-run' => true])
            ->expectsOutputToContain('未読件数=2')
            ->expectsOutputToContain('--dry-run 指定のため')
            ->assertSuccessful();

        $this->assertSame(2, Message::where('is_read', false)->count());
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_local_only_updates_db_without_beds24_call(): void
    {
        $this->seedUnread(2);

        $this->artisan('beds24:mark-all-read', ['--local-only' => true])
            ->expectsConfirmation('2件を既読化します（local のみ）。続けますか？', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, Message::where('is_read', false)->count());
        Http::assertNotSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_normal_run_updates_local_and_calls_beds24(): void
    {
        $this->seedUnread(2);

        $this->artisan('beds24:mark-all-read')
            ->expectsConfirmation('2件を既読化します（local + beds24 同期）。続けますか？', 'yes')
            ->assertSuccessful();

        $this->assertSame(0, Message::where('is_read', false)->count());
        Http::assertSent(fn ($req) => $req->method() === 'POST');
    }

    public function test_no_targets_exits_early(): void
    {
        $this->artisan('beds24:mark-all-read')
            ->expectsOutputToContain('既読化する対象がありません')
            ->assertSuccessful();
    }
}
