<?php

namespace Tests\Feature\Services\AI;

use App\Models\Building;
use App\Models\Listing;
use App\Models\Message;
use App\Services\AI\ReplyDrafter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReplyDrafterTest extends TestCase
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
            'anthropic.draft_max_tokens' => 1024,
        ]);
    }

    private function makeMessage(string $text = 'wifi password please'): Message
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
    private function claudeReply(string $draft, int $inputTokens = 500, int $outputTokens = 200): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $draft]],
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ];
    }

    public function test_generate_saves_draft_and_computes_cost(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('Wi-Fi のパスワードは 12345 です。')),
        ]);

        $msg = $this->makeMessage();
        $draft = app(ReplyDrafter::class)->generate(
            sourceMessage: $msg,
            userPrompt: null,
            language: 'ja',
        );

        $this->assertSame($msg->id, $draft->message_id);
        $this->assertSame('Wi-Fi のパスワードは 12345 です。', $draft->draft_text);
        $this->assertSame('ja', $draft->language);
        $this->assertSame(500, $draft->input_tokens);
        $this->assertSame(200, $draft->output_tokens);
        // 500 * 3 + 200 * 15 = 1500 + 3000 = 4500 per 1M = 0.0045
        $this->assertSame('0.004500', $draft->cost_usd);
        $this->assertNull($draft->previous_draft);
    }

    public function test_generate_with_existing_draft_triggers_rewrite_mode(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('修正後の下書き')),
        ]);

        $msg = $this->makeMessage();
        $draft = app(ReplyDrafter::class)->generate(
            sourceMessage: $msg,
            userPrompt: '簡潔にして',
            language: 'ja',
            existingDraft: '以前の下書きテキスト',
        );

        $this->assertSame('修正後の下書き', $draft->draft_text);
        $this->assertSame('以前の下書きテキスト', $draft->previous_draft);
        $this->assertSame('簡潔にして', $draft->prompt);

        Http::assertSent(function ($req) {
            $body = $req->data();
            $userContent = $body['messages'][0]['content'];
            $system = $body['system'];

            return str_contains($system, 'current draft')
                && str_contains($userContent, '以前の下書きテキスト')
                && str_contains($userContent, '簡潔にして')
                && str_contains($userContent, 'wifi password please');
        });
    }

    public function test_generate_language_instruction_english(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('The Wi-Fi password is 12345.')),
        ]);

        app(ReplyDrafter::class)->generate(
            sourceMessage: $this->makeMessage(),
            userPrompt: null,
            language: 'en',
        );

        // 現行プロンプトは "You MUST write the entire reply in English" 形式。
        Http::assertSent(fn ($req) => str_contains($req->data()['system'], 'write the entire reply in English'));
    }

    public function test_generate_language_other_uses_language_label(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('...')),
        ]);

        // vi は SUPPORTED 拡張で組み込みになったので、テストにはサポート外の言語ラベルを使う。
        app(ReplyDrafter::class)->generate(
            sourceMessage: $this->makeMessage(),
            userPrompt: null,
            language: 'other',
            languageLabel: 'Swahili',
        );

        Http::assertSent(fn ($req) => str_contains($req->data()['system'], 'write the entire reply in Swahili'));
    }

    public function test_generate_trims_whitespace_from_draft(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply("\n\n  padded reply  \n\n")),
        ]);

        $draft = app(ReplyDrafter::class)->generate(
            sourceMessage: $this->makeMessage(),
            userPrompt: null,
            language: 'ja',
        );

        $this->assertSame('padded reply', $draft->draft_text);
    }

    public function test_generate_with_history_sends_multi_turn_messages(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('ok')),
        ]);

        // 会話履歴: guest → host → guest（最後）
        $m1 = $this->makeMessage('first guest msg');
        $m1->update(['source' => 'guest', 'beds24_message_id' => 1001]);
        $m2 = $this->makeMessage('host reply 1');
        $m2->update(['source' => 'host', 'beds24_message_id' => 1002]);
        $m3 = $this->makeMessage('follow up question');
        $m3->update(['source' => 'guest', 'beds24_message_id' => 1003]);

        $history = Message::query()->orderBy('id')->get();

        app(ReplyDrafter::class)->generate(
            sourceMessage: $m3,
            userPrompt: null,
            language: 'ja',
            conversationHistory: $history,
        );

        Http::assertSent(function ($req) {
            $body = $req->data();
            $messages = $body['messages'];

            // 3 turns: user (guest), assistant (host), user (guest + instruction)
            return count($messages) === 3
                && $messages[0]['role'] === 'user'
                && str_contains($messages[0]['content'], 'first guest msg')
                && $messages[1]['role'] === 'assistant'
                && str_contains($messages[1]['content'], 'host reply 1')
                && $messages[2]['role'] === 'user'
                && str_contains($messages[2]['content'], 'follow up question')
                && str_contains($messages[2]['content'], 'Return only the reply text');
        });
    }

    public function test_generate_with_history_merges_consecutive_same_role(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('ok')),
        ]);

        // guest が連続で2件送ってきたパターン
        $m1 = $this->makeMessage('first');
        $m1->update(['source' => 'guest', 'beds24_message_id' => 2001]);
        $m2 = $this->makeMessage('second');
        $m2->update(['source' => 'guest', 'beds24_message_id' => 2002]);

        $history = Message::query()->orderBy('id')->get();

        app(ReplyDrafter::class)->generate(
            sourceMessage: $m2,
            userPrompt: null,
            language: 'ja',
            conversationHistory: $history,
        );

        Http::assertSent(function ($req) {
            $messages = $req->data()['messages'];

            // 連続 guest は 1 user turn に merge → 全体で 1 message
            return count($messages) === 1
                && $messages[0]['role'] === 'user'
                && str_contains($messages[0]['content'], 'first')
                && str_contains($messages[0]['content'], 'second');
        });
    }

    public function test_generate_includes_building_and_listing_info_in_system_prompt(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('ok')),
        ]);

        $building = Building::create([
            'name' => 'テスト建物',
            'is_active' => true,
            'info_markdown' => '住所: 東京都テスト',
        ]);
        Listing::create([
            'building_id' => $building->id,
            'beds24_room_id' => 300,
            'beds24_property_id' => 200,
            'name' => 'テスト部屋',
            'display_name' => 'テスト部屋',
            'is_active' => true,
            'info_markdown' => 'wifi: test-net / pw: abc123',
        ]);

        app(ReplyDrafter::class)->generate(
            sourceMessage: $this->makeMessage(),
            userPrompt: null,
            language: 'ja',
        );

        Http::assertSent(function ($req) {
            $system = $req->data()['system'];

            return str_contains($system, '## Property Information')
                && str_contains($system, 'Building: テスト建物')
                && str_contains($system, '住所: 東京都テスト')
                && str_contains($system, 'Listing: テスト部屋')
                && str_contains($system, 'wifi: test-net');
        });
    }

    public function test_generate_with_history_excludes_system_messages(): void
    {
        Http::fake([
            self::ANTHROPIC_URL => Http::response($this->claudeReply('ok')),
        ]);

        $m1 = $this->makeMessage('a system notification');
        $m1->update(['source' => 'system', 'beds24_message_id' => 3001]);
        $m2 = $this->makeMessage('real guest question');
        $m2->update(['source' => 'guest', 'beds24_message_id' => 3002]);

        $history = Message::query()->orderBy('id')->get();

        app(ReplyDrafter::class)->generate(
            sourceMessage: $m2,
            userPrompt: null,
            language: 'ja',
            conversationHistory: $history,
        );

        Http::assertSent(function ($req) {
            $messages = $req->data()['messages'];

            return count($messages) === 1
                && str_contains($messages[0]['content'], 'real guest question')
                && ! str_contains($messages[0]['content'], 'a system notification');
        });
    }
}
