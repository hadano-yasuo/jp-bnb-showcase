<?php

namespace Tests\Feature\Http;

use App\Jobs\AI\TranslateGuestChannelMessageJob;
use App\Mail\ContactAckMail;
use App\Mail\ContactSubmissionMail;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ContactControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['public_hp.domain' => 'example.test']);
        // 翻訳ジョブは Claude API を叩くのでテスト中は queue にとどめる
        Queue::fake([TranslateGuestChannelMessageJob::class]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => '山田 太郎',
            'email' => 'yamada@example.com',
            'message' => "はじめまして。\nArea Aの 2 階を検討中です。",
            'website' => '',
            'form_ts' => Crypt::encryptString((string) (time() - 5)),
        ], $overrides);
    }

    private function contactRequest(): TestResponse
    {
        return $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload());
    }

    public function test_show_renders_form(): void
    {
        $this->get('http://example.test/contact')->assertOk();
    }

    public function test_store_creates_channel_and_first_message_and_redirects_to_chat(): void
    {
        Mail::fake();

        $this->contactRequest();

        $this->assertSame(1, GuestChannel::count());
        $this->assertSame(1, GuestChannelMessage::count());

        $channel = GuestChannel::first();
        $this->assertSame('yamada@example.com', $channel->guest_email);
        $this->assertSame('山田 太郎', $channel->guest_name);
        $this->assertSame(48, strlen($channel->token));
        $this->assertEqualsWithDelta(
            now()->addDays(90)->timestamp,
            $channel->expires_at->timestamp,
            5,
        );

        $message = GuestChannelMessage::first();
        $this->assertSame(GuestChannelMessage::SOURCE_GUEST, $message->source);
        $this->assertStringContainsString('Area Aの 2 階を検討中です', $message->message);
    }

    public function test_store_redirects_to_chat_url(): void
    {
        Mail::fake();

        $response = $this->contactRequest();

        $channel = GuestChannel::first();
        $response->assertRedirect('http://example.test/chat/'.$channel->token);
    }

    public function test_store_sends_ack_mail_to_guest_with_chat_url(): void
    {
        Mail::fake();

        $this->contactRequest();

        Mail::assertSent(ContactAckMail::class, function (ContactAckMail $mail) {
            $channel = GuestChannel::first();
            $expectedUrl = route('chat.show', ['token' => $channel->token]);

            return $mail->hasTo('yamada@example.com')
                && $mail->chatUrl === $expectedUrl
                && $mail->guestName === '山田 太郎';
        });
    }

    public function test_store_sends_submission_mail_to_host(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'contact@example.com']);

        $this->contactRequest();

        Mail::assertSent(ContactSubmissionMail::class, function (ContactSubmissionMail $mail) {
            return $mail->hasTo('contact@example.com')
                && isset($mail->data['メール'])
                && $mail->data['メール'] === 'yamada@example.com';
        });
    }

    public function test_store_validation_fails_on_missing_email(): void
    {
        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'email' => null,
                'message' => 'test',
            ]))
            ->assertRedirect('http://example.test/contact')
            ->assertSessionHasErrors('email');
    }

    public function test_store_validation_fails_on_missing_name(): void
    {
        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'name' => null,
            ]))
            ->assertRedirect('http://example.test/contact')
            ->assertSessionHasErrors('name');
    }

    public function test_store_rejects_honeypot_submission(): void
    {
        Mail::fake();

        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'website' => 'https://spam.example.com',
            ]))
            ->assertStatus(422);

        $this->assertSame(0, GuestChannel::count());
        Mail::assertNothingSent();
    }

    public function test_store_rejects_too_fast_submission(): void
    {
        Mail::fake();

        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'form_ts' => Crypt::encryptString((string) time()),
            ]))
            ->assertStatus(422);

        $this->assertSame(0, GuestChannel::count());
        Mail::assertNothingSent();
    }

    public function test_store_rejects_missing_form_ts(): void
    {
        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'form_ts' => '',
            ]))
            ->assertStatus(422);

        $this->assertSame(0, GuestChannel::count());
    }

    public function test_store_with_room_slug_prepends_system_message_and_adds_mail_data(): void
    {
        Mail::fake();

        $this->contactRequest_withRoom('sample-location-2f');

        $channel = GuestChannel::first();
        $this->assertNotNull($channel);
        $this->assertSame(2, $channel->messages()->count());

        $first = $channel->messages()->orderBy('id')->first();
        $this->assertSame(GuestChannelMessage::SOURCE_SYSTEM, $first->source);
        $this->assertStringContainsString('Sample Stay Shibaura 2F', $first->message);

        Mail::assertSent(ContactSubmissionMail::class, function (ContactSubmissionMail $mail) {
            return isset($mail->data['対象の部屋'])
                && $mail->data['対象の部屋'] === 'Sample Stay Shibaura 2F';
        });
    }

    public function test_store_rejects_unknown_room_slug(): void
    {
        $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'room_slug' => 'invalid-room',
            ]))
            ->assertRedirect('http://example.test/contact')
            ->assertSessionHasErrors('room_slug');
    }

    private function contactRequest_withRoom(string $roomSlug): TestResponse
    {
        return $this->from('http://example.test/contact')
            ->post('http://example.test/contact', $this->validPayload([
                'room_slug' => $roomSlug,
            ]));
    }
}
