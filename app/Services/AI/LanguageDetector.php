<?php

namespace App\Services\AI;

use App\Models\GuestChannelMessage;
use App\Models\Message;
use App\Services\AI\Exceptions\ClaudeException;
use Illuminate\Support\Facades\Log;

/**
 * ゲストメッセージの言語を判定するサービス。
 *
 * サポート言語: ja / en / zh / ko / es / fr / de / pt / th / vi（それ以外は other + 実言語名）。
 * ゲスト登録フォームの言語スイッチャーと同じセットを維持する。
 * 初回のみ Claude API を呼び、結果を messages.detected_language にキャッシュ。
 */
class LanguageDetector
{
    public const SUPPORTED = ['ja', 'en', 'zh', 'ko', 'es', 'fr', 'de', 'pt', 'th', 'vi'];

    public const OTHER = 'other';

    public function __construct(private readonly ClaudeClient $client) {}

    /**
     * @return array{language: string, label: string|null}
     */
    public function detect(Message|GuestChannelMessage $message): array
    {
        if ($message->detected_language !== null) {
            return [
                'language' => $message->detected_language,
                'label' => $message->detected_language_label,
            ];
        }

        $system = <<<'PROMPT'
You are a strict language detector. Respond with ONLY a JSON object, no other text.

Format: {"code": "<code>", "label": "<label>"}

Rules (match to the primary language of the message):
- Japanese → {"code": "ja", "label": "Japanese"}
- English → {"code": "en", "label": "English"}
- Chinese (Simplified or Traditional) → {"code": "zh", "label": "Chinese"}
- Korean → {"code": "ko", "label": "Korean"}
- Spanish → {"code": "es", "label": "Spanish"}
- French → {"code": "fr", "label": "French"}
- German → {"code": "de", "label": "German"}
- Portuguese (any variant) → {"code": "pt", "label": "Portuguese"}
- Thai → {"code": "th", "label": "Thai"}
- Vietnamese → {"code": "vi", "label": "Vietnamese"}
- Any other language → {"code": "other", "label": "<the actual language name in English>"}

If the message is too short to determine (only numbers/URLs/emojis), default to {"code": "en", "label": "English"}.
PROMPT;

        $response = $this->client->createMessage(
            system: $system,
            messages: [[
                'role' => 'user',
                'content' => $message->message,
            ]],
            maxTokens: 50,
        );

        $parsed = $this->parseResponse($response['text']);
        $code = in_array($parsed['code'], self::SUPPORTED, true) ? $parsed['code'] : self::OTHER;
        $label = $parsed['label'];

        $message->update([
            'detected_language' => $code,
            'detected_language_label' => $label,
        ]);

        return ['language' => $code, 'label' => $label];
    }

    /**
     * @return array{code: string, label: string|null}
     */
    private function parseResponse(string $text): array
    {
        $trimmed = trim($text);
        $decoded = json_decode($trimmed, true);

        if (! is_array($decoded) || ! isset($decoded['code']) || ! is_string($decoded['code'])) {
            Log::warning('LanguageDetector: unexpected response, falling back to other', [
                'raw' => $text,
            ]);
            throw new ClaudeException(
                message: 'LanguageDetector received invalid JSON from Claude',
                context: ['raw' => $text],
            );
        }

        return [
            'code' => $decoded['code'],
            'label' => isset($decoded['label']) && is_string($decoded['label']) ? $decoded['label'] : null,
        ];
    }
}
