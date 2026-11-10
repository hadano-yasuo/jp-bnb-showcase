<?php

namespace App\Services\AI;

use App\Services\AI\Exceptions\ClaudeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Anthropic Messages API 呼び出しの低レベルクライアント。
 *
 * https://docs.anthropic.com/en/api/messages
 *
 * このクラスは HTTP と例外変換だけ担当し、プロンプト設計は上位サービス
 * （ReplyDrafter, LanguageDetector）に委ねる。
 */
class ClaudeClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $model,
        private readonly string $apiVersion,
        private readonly int $httpTimeout,
    ) {}

    /**
     * Messages API に単発リクエスト。
     *
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int, stop_reason: string}
     */
    public function createMessage(
        string $system,
        array $messages,
        int $maxTokens,
        ?string $model = null,
    ): array {
        $payload = [
            'model' => $model ?? $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => $messages,
        ];

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
            'content-type' => 'application/json',
        ])
            ->timeout($this->httpTimeout)
            ->post($this->baseUrl.'/messages', $payload);

        if ($response->failed()) {
            throw new ClaudeException(
                message: 'Claude API request failed',
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ClaudeException(
                message: 'Unexpected Claude API response (non-json)',
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $content = $data['content'] ?? null;
        if (! is_array($content) || $content === []) {
            throw new ClaudeException(
                message: 'Claude API returned empty content',
                context: ['response' => $data],
            );
        }

        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        if ($text === '') {
            throw new ClaudeException(
                message: 'Claude API returned no text blocks',
                context: ['response' => $data],
            );
        }

        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        Log::debug('claude api call', [
            'model' => $data['model'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'stop_reason' => $data['stop_reason'] ?? null,
        ]);

        return [
            'text' => $text,
            'model' => (string) ($data['model'] ?? $this->model),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'stop_reason' => (string) ($data['stop_reason'] ?? ''),
        ];
    }

    /**
     * Vision 対応の multimodal リクエスト。
     * content は [{'type': 'text', 'text': '...'}, {'type': 'image', 'source': {...}}] 形式。
     *
     * @param  list<array{type: 'text', text: string}|array{type: 'image', source: array{type: 'base64', media_type: string, data: string}}>  $userContent
     * @return array{text: string, model: string, input_tokens: int, output_tokens: int, stop_reason: string}
     */
    public function createMessageWithImages(
        string $system,
        array $userContent,
        int $maxTokens,
        ?string $model = null,
    ): array {
        $payload = [
            'model' => $model ?? $this->model,
            'max_tokens' => $maxTokens,
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => $userContent,
            ]],
        ];

        $response = Http::withHeaders([
            'x-api-key' => $this->apiKey,
            'anthropic-version' => $this->apiVersion,
            'content-type' => 'application/json',
        ])
            ->timeout($this->httpTimeout)
            ->post($this->baseUrl.'/messages', $payload);

        if ($response->failed()) {
            throw new ClaudeException(
                message: 'Claude API multimodal request failed',
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $data = $response->json();
        if (! is_array($data)) {
            throw new ClaudeException(
                message: 'Unexpected Claude API response (non-json)',
                httpStatus: $response->status(),
                context: ['body' => $response->body()],
            );
        }

        $content = $data['content'] ?? null;
        if (! is_array($content) || $content === []) {
            throw new ClaudeException(
                message: 'Claude API returned empty content',
                context: ['response' => $data],
            );
        }

        $text = '';
        foreach ($content as $block) {
            if (($block['type'] ?? null) === 'text' && isset($block['text'])) {
                $text .= (string) $block['text'];
            }
        }

        if ($text === '') {
            throw new ClaudeException(
                message: 'Claude API returned no text blocks',
                context: ['response' => $data],
            );
        }

        $usage = $data['usage'] ?? [];
        $inputTokens = (int) ($usage['input_tokens'] ?? 0);
        $outputTokens = (int) ($usage['output_tokens'] ?? 0);

        Log::debug('claude vision api call', [
            'model' => $data['model'] ?? null,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ]);

        return [
            'text' => $text,
            'model' => (string) ($data['model'] ?? $this->model),
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'stop_reason' => (string) ($data['stop_reason'] ?? ''),
        ];
    }
}
