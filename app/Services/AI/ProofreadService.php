<?php

namespace App\Services\AI;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 送信直前の日本語本文を Haiku 4.5 で誤字脱字・文法チェックするサービス。
 *
 * - 出力は JSON 固定 ({"ok": true} または {"ok": false, issues, suggestion})
 * - 判定に失敗した場合は fail-open（ok=true 扱い）で送信フローを止めない
 * - 誤検知を抑えるため、固有名詞・カタカナ英語・軽微な文体差はスルーする指示
 * - 極短文（15文字未満）は誤検知の温床なのでチェック自体をスキップ
 */
class ProofreadService
{
    private const MIN_LENGTH = 15;

    public function __construct(
        private readonly ClaudeClient $client,
        private readonly string $model,
    ) {}

    /**
     * @return array{ok: true}|array{ok: false, issues: list<array{type: string, quote: string, reason: string}>, suggestion: string}
     */
    public function check(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '' || mb_strlen($trimmed) < self::MIN_LENGTH) {
            return ['ok' => true];
        }

        try {
            $response = $this->client->createMessage(
                system: $this->buildSystemPrompt(),
                messages: [['role' => 'user', 'content' => $trimmed]],
                maxTokens: 2048,
                model: $this->model,
            );
        } catch (Throwable $e) {
            Log::warning('proofread claude call failed', ['error' => $e->getMessage()]);

            return ['ok' => true];
        }

        return $this->parseResponse($response['text']);
    }

    private function buildSystemPrompt(): string
    {
        return <<<'PROMPT'
You are a strict Japanese proofreader for outgoing replies from a Japanese vacation rental (民泊) host to a guest.

Analyze the input text and respond with EXACTLY one JSON object. Output ONLY the JSON, no markdown, no code fence, no prose before or after.

If the text has no meaningful issues:
{"ok": true}

If there ARE issues worth flagging:
{
  "ok": false,
  "issues": [
    {"type": "typo|grammar|awkward", "quote": "the exact problematic phrase copied from the text", "reason": "日本語で簡潔に理由"}
  ],
  "suggestion": "the entire text rewritten with corrections applied, keeping the original tone"
}

What TO flag:
- 誤字 (typos): wrong kanji, missing hiragana, wrong okurigana, obvious typing errors
- 明らかな文法エラー: missing particles, tense inconsistency, subject-verb mismatch
- 不自然な言い回し: only if clearly awkward or would confuse a native reader

What NOT to flag:
- Proper nouns: property names (Building A, Building B, etc.), guest names, addresses
- Casual polite tone (です/ます 調はそのままでOK)
- Foreign words, katakana loan words, English phrases
- Emoji, punctuation preferences, line-break style
- Minor stylistic differences that are just personal preference

Rules:
- If you are uncertain whether something is an actual error, err toward {"ok": true}.
- Preserve the original tone, warmth, and length in the suggestion.
- Do not add or remove information in the suggestion.
- Output ONLY the JSON object.
PROMPT;
    }

    /**
     * @return array{ok: true}|array{ok: false, issues: list<array{type: string, quote: string, reason: string}>, suggestion: string}
     */
    private function parseResponse(string $raw): array
    {
        $text = trim($raw);

        // まれに前後に説明が付いた場合は最初の { から最後の } を抽出
        if (! str_starts_with($text, '{')) {
            $start = strpos($text, '{');
            $end = strrpos($text, '}');
            if ($start !== false && $end !== false && $end > $start) {
                $text = substr($text, $start, $end - $start + 1);
            }
        }

        /** @var mixed $parsed */
        $parsed = json_decode($text, true);
        if (! is_array($parsed) || ! array_key_exists('ok', $parsed)) {
            Log::warning('proofread returned unparseable JSON', ['raw' => $raw]);

            return ['ok' => true];
        }

        if ($parsed['ok'] === true) {
            return ['ok' => true];
        }

        $issues = [];
        if (isset($parsed['issues']) && is_array($parsed['issues'])) {
            foreach ($parsed['issues'] as $issue) {
                if (! is_array($issue)) {
                    continue;
                }
                $issues[] = [
                    'type' => (string) ($issue['type'] ?? 'other'),
                    'quote' => (string) ($issue['quote'] ?? ''),
                    'reason' => (string) ($issue['reason'] ?? ''),
                ];
            }
        }

        // issues が空なら実質 ok とみなす（Claude が ok:false と ok:true の判断を誤ることがあるため）
        if ($issues === []) {
            return ['ok' => true];
        }

        return [
            'ok' => false,
            'issues' => $issues,
            'suggestion' => (string) ($parsed['suggestion'] ?? ''),
        ];
    }
}
