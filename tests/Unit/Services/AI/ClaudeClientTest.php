<?php

namespace Tests\Unit\Services\AI;

use App\Services\AI\ClaudeClient;
use App\Services\AI\Exceptions\ClaudeException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ClaudeClientTest extends TestCase
{
    private const API_KEY = 'sk-ant-test';

    private const BASE_URL = 'https://api.anthropic.example/v1';

    private const MODEL = 'claude-sonnet-4-6';

    private const API_VERSION = '2023-06-01';

    private function makeClient(): ClaudeClient
    {
        return new ClaudeClient(
            apiKey: self::API_KEY,
            baseUrl: self::BASE_URL,
            model: self::MODEL,
            apiVersion: self::API_VERSION,
            httpTimeout: 5,
        );
    }

    public function test_create_message_sends_expected_payload_and_headers(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response([
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hello there.']],
                'model' => self::MODEL,
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 42, 'output_tokens' => 7],
            ]),
        ]);

        $result = $this->makeClient()->createMessage(
            system: 'be brief',
            messages: [['role' => 'user', 'content' => 'hi']],
            maxTokens: 100,
        );

        $this->assertSame('Hello there.', $result['text']);
        $this->assertSame(self::MODEL, $result['model']);
        $this->assertSame(42, $result['input_tokens']);
        $this->assertSame(7, $result['output_tokens']);
        $this->assertSame('end_turn', $result['stop_reason']);

        Http::assertSent(function ($req) {
            $body = $req->data();

            return $req->method() === 'POST'
                && $req->hasHeader('x-api-key', self::API_KEY)
                && $req->hasHeader('anthropic-version', self::API_VERSION)
                && $req->hasHeader('content-type', 'application/json')
                && $body['model'] === self::MODEL
                && $body['max_tokens'] === 100
                && $body['system'] === 'be brief'
                && $body['messages'] === [['role' => 'user', 'content' => 'hi']];
        });
    }

    public function test_create_message_concatenates_multiple_text_blocks(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Part 1. '],
                    ['type' => 'text', 'text' => 'Part 2.'],
                ],
                'model' => self::MODEL,
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]),
        ]);

        $result = $this->makeClient()->createMessage(
            system: '',
            messages: [['role' => 'user', 'content' => 'x']],
            maxTokens: 10,
        );

        $this->assertSame('Part 1. Part 2.', $result['text']);
    }

    public function test_create_message_uses_override_model(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'x']],
                'model' => 'claude-haiku-4-5',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]),
        ]);

        $this->makeClient()->createMessage(
            system: '',
            messages: [['role' => 'user', 'content' => 'x']],
            maxTokens: 10,
            model: 'claude-haiku-4-5',
        );

        Http::assertSent(fn ($req) => $req->data()['model'] === 'claude-haiku-4-5');
    }

    public function test_http_error_throws_claude_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response(['error' => 'invalid_request'], 400),
        ]);

        $this->expectException(ClaudeException::class);
        $this->expectExceptionMessage('Claude API request failed');

        $this->makeClient()->createMessage(
            system: '',
            messages: [['role' => 'user', 'content' => 'x']],
            maxTokens: 10,
        );
    }

    public function test_empty_content_throws_claude_exception(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response([
                'content' => [],
                'model' => self::MODEL,
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
            ]),
        ]);

        $this->expectException(ClaudeException::class);
        $this->expectExceptionMessage('empty content');

        $this->makeClient()->createMessage(
            system: '',
            messages: [['role' => 'user', 'content' => 'x']],
            maxTokens: 10,
        );
    }

    public function test_content_without_text_blocks_throws(): void
    {
        Http::fake([
            self::BASE_URL.'/messages' => Http::response([
                'content' => [['type' => 'tool_use', 'id' => 'tool_1', 'name' => 'x', 'input' => []]],
                'model' => self::MODEL,
                'stop_reason' => 'tool_use',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 0],
            ]),
        ]);

        $this->expectException(ClaudeException::class);
        $this->expectExceptionMessage('no text blocks');

        $this->makeClient()->createMessage(
            system: '',
            messages: [['role' => 'user', 'content' => 'x']],
            maxTokens: 10,
        );
    }
}
