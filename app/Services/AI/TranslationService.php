<?php

namespace App\Services\AI;

use App\Models\GuestChannelMessage;
use App\Models\Message;
use App\Services\AI\Exceptions\ClaudeException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * メッセージの翻訳サービス。
 *
 * - incoming（非日本語 guest）→ 日本語で messages.message_ja に保存し併記表示に使う
 * - outgoing（host reply）→ 日本語入力を最新ゲスト言語に翻訳して送信
 *
 * Haiku 4.5 を使う（config/anthropic.php の translation_model）。翻訳は定型作業
 * なので Sonnet を使う必要はなく、コストが約1/5になる。
 */
class TranslationService
{
    /**
     * サポート言語コード → 英語名（プロンプト内で使う言語名）。
     * LanguageDetector::SUPPORTED と同期させること。
     */
    private const LANG_NAMES = [
        'ja' => 'Japanese',
        'en' => 'English',
        'zh' => 'Chinese',
        'ko' => 'Korean',
        'es' => 'Spanish',
        'fr' => 'French',
        'de' => 'German',
        'pt' => 'Portuguese',
        'th' => 'Thai',
        'vi' => 'Vietnamese',
    ];

    public function __construct(
        private readonly ClaudeClient $client,
        private readonly string $translationModel,
    ) {}

    /**
     * メッセージを日本語に翻訳し message_ja に保存する（guest / host 両対応、
     * beds24 Message とゲストチャネル GuestChannelMessage の両方で使う）。
     *
     * - detected_language が 'ja' の場合はスキップ（元が日本語）
     * - message_ja が既にセット済みならスキップ（冪等）
     * - detected_language が null の場合は先に LanguageDetector を呼ぶ必要あり
     *
     * @return bool 翻訳を実行して保存したら true、スキップなら false
     */
    public function translateGuestMessageToJa(Message|GuestChannelMessage $message): bool
    {
        if ($message->detected_language === 'ja') {
            return false;
        }
        if ($message->message_ja !== null) {
            return false;
        }
        if ($message->detected_language === null) {
            $class = class_basename($message);
            throw new RuntimeException(
                "{$class} {$message->id} has no detected_language. Run LanguageDetector first."
            );
        }

        $sourceLangName = $this->languageName($message->detected_language, $message->detected_language_label);
        $translated = $this->doTranslate($message->message, $sourceLangName, 'Japanese');

        $message->update(['message_ja' => $translated]);

        return true;
    }

    /**
     * 日本語テキストを target 言語に翻訳する（reply 送信時に使用）。
     *
     * @param  string  $targetLangCode  ja/en/es/zh/ko/other
     * @param  string|null  $targetLangLabel  other の場合の実言語名（例: French）
     */
    public function translateFromJa(string $japaneseText, string $targetLangCode, ?string $targetLangLabel = null): string
    {
        if ($targetLangCode === 'ja') {
            return $japaneseText;
        }

        $targetLangName = $this->languageName($targetLangCode, $targetLangLabel);

        return $this->doTranslate($japaneseText, 'Japanese', $targetLangName);
    }

    private function doTranslate(string $text, string $sourceLangName, string $targetLangName): string
    {
        $system = <<<PROMPT
You are a strict machine translator. Translate the user's message from {$sourceLangName} to {$targetLangName}.

Absolute rules:
- Translate ONLY what is explicitly written. Do NOT add information, context, background, or reasoning that is not in the source text.
- Do NOT infer motivation, situation, or facts. If the source is short, the output must be short.
- If the source is a single word or fragment, output a single word or fragment. Do NOT expand into a full sentence.
- Preserve tone matching the original (formal, casual, polite, blunt).
- Preserve line breaks, punctuation, emojis, URLs, numbers, and formatting exactly.
- Do NOT translate proper nouns (Japanese property/building names like Building A, Building B, personal names) — leave them as-is.
- Do NOT add greetings, sign-offs, apologies, or filler that the source does not contain.
- Do NOT add explanations, notes, quote marks, or code fences around the output.
- Output ONLY the translation, nothing else.
PROMPT;

        $response = $this->client->createMessage(
            system: $system,
            messages: [[
                'role' => 'user',
                'content' => $text,
            ]],
            maxTokens: 2048,
            model: $this->translationModel,
        );

        Log::debug('translation completed', [
            'source' => $sourceLangName,
            'target' => $targetLangName,
            'input_tokens' => $response['input_tokens'],
            'output_tokens' => $response['output_tokens'],
            'model' => $response['model'],
        ]);

        $translated = trim($response['text']);

        if ($translated === '') {
            throw new ClaudeException(
                message: 'Translation returned empty text',
                context: ['response' => $response],
            );
        }

        return $translated;
    }

    private function languageName(string $code, ?string $label): string
    {
        if (isset(self::LANG_NAMES[$code])) {
            return self::LANG_NAMES[$code];
        }

        // 'other' の場合は LanguageDetector が入れた label を使う
        return $label ?? 'the source language';
    }
}
