<?php

namespace App\Services\AI;

use App\Models\Message;
use App\Models\MessageDraft;
use Illuminate\Support\Collection;

/**
 * ゲストメッセージへの返信下書きを AI で生成し、message_drafts に保存するサービス。
 *
 * generate() は毎回 message_drafts に新規 row を追加する (履歴・学習データとして残す)。
 * 既存 draft を渡すと「その内容を踏まえたリライト」モードになり、
 * 人間の手動編集を保持しつつプロンプトの指示を反映する。
 *
 * プロンプト組み立ての詳細は ReplyPromptBuilder に委譲。GuestChannel 側の
 * GuestChannelReplyDrafter とプロンプトエンジニアリング部分を共通化している。
 *
 * @see ReplyPromptBuilder
 * @see GuestChannelReplyDrafter
 */
class ReplyDrafter
{
    /** @var float Sonnet 4.6 の input token 単価 (USD / 1M tokens) */
    private const INPUT_COST_PER_MTOK = 3.0;

    /** @var float Sonnet 4.6 の output token 単価 (USD / 1M tokens) */
    private const OUTPUT_COST_PER_MTOK = 15.0;

    public function __construct(
        private readonly ClaudeClient $client,
        private readonly ReplyPromptBuilder $promptBuilder,
    ) {}

    /**
     * 返信下書きを 1 件生成して MessageDraft を返す。
     *
     * @param  string  $language  ja/en/es/zh/ko/other
     * @param  string|null  $languageLabel  other の時の実言語名
     * @param  string|null  $existingDraft  現在 textarea にあるテキスト (未編集なら null)
     * @param  Collection<int, Message>|null  $conversationHistory  同予約の全メッセージ (sent_at ASC)。
     *                                                              null なら sourceMessage 1 件のみで生成
     */
    public function generate(
        Message $sourceMessage,
        ?string $userPrompt,
        string $language,
        ?string $languageLabel = null,
        ?string $existingDraft = null,
        ?Collection $conversationHistory = null,
    ): MessageDraft {
        $history = $this->historyToArray($sourceMessage, $conversationHistory);

        $prompt = $this->promptBuilder->build(
            beds24RoomId: $sourceMessage->beds24_room_id,
            history: $history,
            language: $language,
            languageLabel: $languageLabel,
            userPrompt: $userPrompt,
            existingDraft: $existingDraft,
        );

        $response = $this->client->createMessage(
            system: $prompt['system'],
            messages: $prompt['messages'],
            maxTokens: (int) config('anthropic.draft_max_tokens'),
        );

        $costUsd = ($response['input_tokens'] * self::INPUT_COST_PER_MTOK
            + $response['output_tokens'] * self::OUTPUT_COST_PER_MTOK) / 1_000_000;

        return MessageDraft::create([
            'message_id' => $sourceMessage->id,
            'prompt' => $userPrompt,
            'previous_draft' => $existingDraft !== null && trim($existingDraft) !== '' ? $existingDraft : null,
            'language' => $language,
            'language_label' => $languageLabel,
            'draft_text' => trim($response['text']),
            'model' => $response['model'],
            'input_tokens' => $response['input_tokens'],
            'output_tokens' => $response['output_tokens'],
            'cost_usd' => round($costUsd, 6),
            'generated_at' => now(),
        ]);
    }

    /**
     * Message コレクションを ReplyPromptBuilder が受け取る primitive 配列に変換。
     * history が null なら sourceMessage 1 件だけの user turn を返す (従来動作)。
     *
     * @param  Collection<int, Message>|null  $history
     * @return list<array{role: string, content: string}>
     */
    private function historyToArray(Message $source, ?Collection $history): array
    {
        if ($history === null || $history->isEmpty()) {
            return [['role' => 'user', 'content' => $source->message]];
        }

        return $history
            ->filter(fn (Message $m) => $m->source !== Message::SOURCE_SYSTEM)
            ->map(fn (Message $m) => [
                'role' => $m->source === Message::SOURCE_GUEST ? 'user' : 'assistant',
                'content' => $m->message,
            ])
            ->values()
            ->all();
    }
}
