<?php

namespace Tests\Feature\Services\AI;

use App\Models\Message;
use App\Services\AI\Exceptions\ClaudeException;
use App\Services\AI\LanguageDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LanguageDetectorTest extends TestCase
{
    use RefreshDatabase;

    private const ANTHROPIC_URL = 'https://api.anthropic.example/v1/messages';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'anthropic.api_key' => 'sk-ant-test',
            'anthropic.base_url' => 'https://api.anthropic.example/v1',
            'anthropic.model' => 'claude-sonnet-4-6',
            'anthropic.api_version' => '2023-06-01',
            'anthropic.http_timeout' => 5,
        ]);
    }

    private function makeMessage(string $text): Message
    {
        return Message::create([
            'beds24_message_id' => 999,
            'beds24_booking_id' => 100,
            'beds24_property_id' => 200,
            'beds24_room_id' => 300,
            'source' => 'guest',
            'message' => $text,
            'is_read' => false,
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function claudeReply(string $json): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $json]],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 10],
        ];
    }

    public function test_cached_result_is_returned_without_api_call(): void
    {
        $msg = $this->makeMessage('Hello');
        $msg->update(['detected_language' => 'en', 'detected_language_label' => 'English']);

        $result = app(LanguageDetector::class)->detect($msg);

        $this->assertSame('en', $result['language']);
        $this->assertSame('English', $result['label']);
        Http::assertNothingSent();
    }

    public function test_supported_language_is_stored(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('{"code":"ja","label":"Japanese"}')),
        ]);

        $msg = $this->makeMessage('こんにちは');

        $result = app(LanguageDetector::class)->detect($msg);

        $this->assertSame('ja', $result['language']);
        $this->assertSame('Japanese', $result['label']);
        $this->assertSame('ja', $msg->fresh()->detected_language);
        $this->assertSame('Japanese', $msg->fresh()->detected_language_label);
    }

    public function test_unsupported_language_becomes_other(): void
    {
        // vi は SUPPORTED に追加済みなので、テストには実際にサポートしていない言語を使う。
        // sw = スワヒリ語（今回対応外）。
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('{"code":"sw","label":"Swahili"}')),
        ]);

        $result = app(LanguageDetector::class)->detect($this->makeMessage('Habari'));

        $this->assertSame('other', $result['language']);
        $this->assertSame('Swahili', $result['label']);
    }

    public function test_invalid_json_response_throws(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('not-json-at-all')),
        ]);

        $this->expectException(ClaudeException::class);
        $this->expectExceptionMessage('invalid JSON');

        app(LanguageDetector::class)->detect($this->makeMessage('???'));
    }
}
