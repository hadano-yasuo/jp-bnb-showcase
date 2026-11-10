<?php

namespace Tests\Feature\Livewire\Public;

use App\Jobs\AI\TranslateGuestChannelMessageJob;
use App\Livewire\Public\GuestChannelChat;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class GuestChannelChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ja');
        // 翻訳ジョブは Claude API を叩くのでテスト中は queue にとどめる
        Queue::fake([TranslateGuestChannelMessageJob::class]);
    }

    private function makeChannel(bool $expired = false): GuestChannel
    {
        $channel = GuestChannel::create([
            'token' => GuestChannel::generateToken(),
            'guest_email' => 'guest@example.com',
            'guest_locale' => 'ja',
            'expires_at' => $expired ? now()->subDay() : now()->addDays(90),
        ]);

        GuestChannelMessage::create([
            'guest_channel_id' => $channel->id,
            'source' => GuestChannelMessage::SOURCE_GUEST,
            'message' => '最初のお問い合わせ内容',
            'sent_at' => now(),
        ]);

        return $channel;
    }

    public function test_mount_loads_channel_by_token(): void
    {
        $channel = $this->makeChannel();

        Livewire::test(GuestChannelChat::class, ['token' => $channel->token])
            ->assertSee('最初のお問い合わせ内容')
            ->assertSee('あなた');
    }

    public function test_mount_404s_when_token_invalid(): void
    {
        Livewire::test(GuestChannelChat::class, ['token' => str_repeat('a', 48)])
            ->assertStatus(404);
    }

    public function test_mount_404s_when_channel_expired(): void
    {
        $channel = $this->makeChannel(expired: true);

        Livewire::test(GuestChannelChat::class, ['token' => $channel->token])
            ->assertStatus(404);
    }

    public function test_send_message_appends_guest_message(): void
    {
        $channel = $this->makeChannel();

        Livewire::test(GuestChannelChat::class, ['token' => $channel->token])
            ->set('body', 'wifi のパスワードを教えてください')
            ->call('sendMessage')
            ->assertSet('body', '')
            ->assertSee('wifi のパスワードを教えてください');

        $this->assertSame(2, $channel->messages()->count());
        $latest = GuestChannelMessage::where('guest_channel_id', $channel->id)
            ->orderByDesc('id')
            ->first();
        $this->assertSame(GuestChannelMessage::SOURCE_GUEST, $latest->source);
        $this->assertSame('wifi のパスワードを教えてください', $latest->message);
    }

    public function test_send_message_validates_required(): void
    {
        $channel = $this->makeChannel();

        Livewire::test(GuestChannelChat::class, ['token' => $channel->token])
            ->set('body', '')
            ->call('sendMessage')
            ->assertHasErrors(['body' => 'required']);

        $this->assertSame(1, $channel->messages()->count());
    }
}
