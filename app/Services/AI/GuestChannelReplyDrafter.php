<?php

namespace App\Services\AI;

use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use Illuminate\Support\Collection;

/**
 * GuestChannel (自社HP 経由の問い合わせ) 向けの返信下書きを AI で生成するサービス。
 *
 * ReplyDrafter (beds24 Message 用) と対応する GuestChannel 版。以下が異なる:
 * - 生成結果は DB 保存しない (ephemeral、textarea へ直接挿入)
 * - 常に日本語で下書き。ゲスト言語への翻訳は既存の translatePreview フローで行う
 * - 会話履歴は GuestChannelMessage を使用
 * - listing 情報は room_slug → beds24_room_id → Listing 経由で解決
 *
 * プロンプト組み立ての詳細は ReplyPromptBuilder に委譲。ReplyDrafter と
 * プロンプトエンジニアリング部分を共通化している。
 *
 * @see ReplyPromptBuilder
 * @see ReplyDrafter
 */
class GuestChannelReplyDrafter
{
    public function __construct(
        private readonly ClaudeClient $client,
        private readonly ReplyPromptBuilder $promptBuilder,
    ) {}

    /**
     * 下書きテキストを生成して返す (常に日本語)。
     *
     * @param  Collection<int, GuestChannelMessage>  $history  同 channel の全メッセージ (sent_at ASC)
     */
    public function generate(
        GuestChannel $channel,
        Collection $history,
        ?string $userPrompt,
        ?string $existingDraft = null,
    ): string {
        $beds24RoomId = $channel->room_slug !== null
            ? (int) config('rooms.'.$channel->room_slug.'.beds24_room_id', 0)
            : 0;

        $historyArray = $this->historyToArray($history);

        $prompt = $this->promptBuilder->build(
            beds24RoomId: $beds24RoomId,
            history: $historyArray,
            language: 'ja',
            userPrompt: $userPrompt,
            existingDraft: $existingDraft,
        );

        $response = $this->client->createMessage(
            system: $prompt['system'],
            messages: $prompt['messages'],
            maxTokens: (int) config('anthropic.draft_max_tokens'),
        );

        return trim($response['text']);
    }

    /**
     * @param  Collection<int, GuestChannelMessage>  $history
     * @return list<array{role: string, content: string}>
     */
    private function historyToArray(Collection $history): array
    {
        return $history
            ->filter(fn (GuestChannelMessage $m) => $m->source !== GuestChannelMessage::SOURCE_SYSTEM)
            ->map(fn (GuestChannelMessage $m) => [
                'role' => $m->source === GuestChannelMessage::SOURCE_GUEST ? 'user' : 'assistant',
                'content' => $m->message,
            ])
            ->values()
            ->all();
    }
}
