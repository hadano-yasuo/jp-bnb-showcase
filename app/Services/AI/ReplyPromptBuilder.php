<?php

namespace App\Services\AI;

/**
 * ReplyDrafter / GuestChannelReplyDrafter が Claude Messages API に投げる
 * system プロンプトと messages 配列を組み立てる責務を持つ共通ビルダー。
 *
 * 入力はすべて model 非依存の primitives:
 * - beds24_room_id (物件情報を注入するため、0 なら注入なし)
 * - history: [{role, content}] の配列 (呼び出し側で source フィルタと変換を済ませる)
 * - language / languageLabel: 出力言語の強制
 * - userPrompt / existingDraft: 追加指示と既存下書き
 *
 * 配列末尾に「返信を作れ」の instruction turn を統合するロジック
 * (同 role 連続マージ + user/assistant 別ハンドリング) はここに集約。
 */
class ReplyPromptBuilder
{
    /**
     * 会話履歴の最大件数 (末尾から数える)。長すぎる会話でトークン消費が爆発しないように上限。
     */
    private const CONVERSATION_HISTORY_LIMIT = 20;

    public function __construct(
        private readonly BuildingInfoResolver $buildingInfoResolver,
    ) {}

    /**
     * @param  list<array{role: string, content: string}>  $history  system メッセージは除外済み前提。空でも可
     * @return array{system: string, messages: list<array{role: string, content: string}>}
     */
    public function build(
        int $beds24RoomId,
        array $history,
        string $language = 'ja',
        ?string $languageLabel = null,
        ?string $userPrompt = null,
        ?string $existingDraft = null,
    ): array {
        $hasExistingDraft = $existingDraft !== null && trim($existingDraft) !== '';

        $propertyInfo = $beds24RoomId !== 0
            ? $this->buildingInfoResolver->resolveByBeds24RoomId($beds24RoomId)
            : '';

        return [
            'system' => $this->buildSystemPrompt($language, $languageLabel, $hasExistingDraft, $propertyInfo),
            'messages' => $this->buildMessages($history, $userPrompt, $hasExistingDraft ? $existingDraft : null),
        ];
    }

    private function buildSystemPrompt(string $language, ?string $languageLabel, bool $hasExistingDraft, string $propertyInfo): string
    {
        $langName = match ($language) {
            'ja' => 'Japanese (日本語)',
            'en' => 'English',
            'zh' => 'Chinese (中文)',
            'ko' => 'Korean (한국어)',
            'es' => 'Spanish (Español)',
            'fr' => 'French (Français)',
            'de' => 'German (Deutsch)',
            'pt' => 'Portuguese (Português)',
            'th' => 'Thai (ไทย)',
            'vi' => 'Vietnamese (Tiếng Việt)',
            default => $languageLabel !== null && $languageLabel !== '' ? $languageLabel : 'the same language as the guest message',
        };

        $langInstruction = "OUTPUT LANGUAGE (STRICT): You MUST write the entire reply in {$langName}. "
            .'This rule overrides the language of the guest message, the language of the conversation history, and the language of any existing draft. '
            .'Even if every prior turn in this conversation is in another language, you MUST still respond in '
            .$langName.'. Do not mix languages. Do not translate proper nouns.';

        $base = <<<PROMPT
You are the host of a small Japanese vacation rental (民泊) business in Tokyo. You reply to guest messages in a polite, warm, and clear tone appropriate for the hospitality industry.

Guidelines:
- {$langInstruction}
- Keep replies concise but complete. Avoid unnecessary greetings/sign-offs.
- Plain text only. No HTML, no Markdown, no bullet lists (many OTAs strip formatting).
- No URLs unless the guest specifically asked for one.
- Do not sign the message with a name. Do not insert placeholders like [Name] or [Property].
- If the guest asks about something you cannot know (specific room availability, prices, dates), politely say you will confirm and get back to them — do not fabricate specifics.
- If a conversation history is provided as prior turns, treat it as context: do not repeat information the guest already has, and do not contradict past commitments.
PROMPT;

        if ($hasExistingDraft) {
            $base .= "\n\nA current draft is provided in the final user turn. Preserve its tone and intent, then apply the user's instruction on top. Keep any edits the user has already made unless they explicitly contradict the new instruction. If the existing draft is in a different language than the required output language above, rewrite it in the required output language while preserving the meaning.";
        }

        if (trim($propertyInfo) !== '') {
            $base .= "\n\n## Property Information (use only when relevant to the guest's question; do not force it in)\n\n".$propertyInfo;
        }

        return $base;
    }

    /**
     * Claude Messages API の messages 配列を組み立てる。
     *
     * - 同 role 連続をマージ (Claude API 制約)
     * - 末尾に「返信を作れ」の instruction を統合:
     *   - 直前が assistant → 新規 user turn として追加
     *   - 直前が user → その user turn の末尾に追記
     *   - 履歴が空 → 単独 user turn として追加
     *
     * @param  list<array{role: string, content: string}>  $history
     * @return list<array{role: string, content: string}>
     */
    private function buildMessages(array $history, ?string $userPrompt, ?string $existingDraft): array
    {
        // 末尾から CONVERSATION_HISTORY_LIMIT 件だけ残す
        $history = array_slice($history, -self::CONVERSATION_HISTORY_LIMIT);

        $messages = [];
        $lastRole = null;

        foreach ($history as $turn) {
            $role = $turn['role'];
            $content = $turn['content'];

            if ($lastRole === $role) {
                $messages[count($messages) - 1]['content'] .= "\n\n".$content;
            } else {
                $messages[] = ['role' => $role, 'content' => $content];
                $lastRole = $role;
            }
        }

        $instruction = $this->buildFinalInstruction($userPrompt, $existingDraft);

        if ($lastRole === 'user') {
            $messages[count($messages) - 1]['content'] .= "\n\n---\n\n".$instruction;
        } else {
            // assistant or empty → 新規 user turn
            $messages[] = ['role' => 'user', 'content' => $instruction];
        }

        return $messages;
    }

    private function buildFinalInstruction(?string $userPrompt, ?string $existingDraft): string
    {
        $parts = [];

        if ($existingDraft !== null) {
            $parts[] = "Current draft (preserve intent, apply the instruction on top):\n".$existingDraft;
        }

        if ($userPrompt !== null && trim($userPrompt) !== '') {
            $parts[] = "Instruction for this draft:\n".$userPrompt;
        } else {
            $parts[] = 'Generate an appropriate reply to the most recent guest message.';
        }

        $parts[] = 'Return only the reply text, nothing else.';

        return implode("\n\n---\n\n", $parts);
    }
}
