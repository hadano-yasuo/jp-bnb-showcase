<?php

namespace Tests\Feature\Livewire\Filament;

use App\Filament\Pages\Conversations\ShowConversation;
use App\Mail\GuestRegistrationLinkMail;
use App\Models\Booking;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * URL 剥奪 OTA (Agoda 等) 対策のメール送信モーダルフロー。
 * beds24 chat には投稿しない → タイムラインには system 行のみ増える。
 */
class EmailSendFromConversationTest extends TestCase
{
    use RefreshDatabase;

    private function makeBookingWithGuestMessage(?string $contactEmail = null): array
    {
        $booking = Booking::create([
            'beds24_booking_id' => 900001,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => '花子',
            'last_name' => '田中',
            'email' => 'fake@guests.airbnb.com',
            'contact_email' => $contactEmail,
            'arrival' => now()->addDays(3)->toDateString(),
            'departure' => now()->addDays(5)->toDateString(),
            'num_adult' => 2,
            'num_child' => 0,
            'raw_payload' => [],
        ]);

        $message = Message::create([
            'beds24_message_id' => 700001,
            'beds24_booking_id' => 900001,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'source' => Message::SOURCE_GUEST,
            'message' => '登録リンクが開けません',
            'sent_at' => now(),
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        return [$booking, $message];
    }

    public function test_open_email_modal_prefills_contact_email_and_body(): void
    {
        [$booking, $msg] = $this->makeBookingWithGuestMessage(contactEmail: 'saved@example.com');

        Livewire::test(ShowConversation::class, ['bookingId' => 900001])
            ->set('body', '登録用リンクです → {{ $guest_registration_url }}')
            ->call('openEmailModal')
            ->assertSet('emailModalOpen', true)
            ->assertSet('emailTo', 'saved@example.com')
            ->assertSet('emailSubject', '')
            ->assertSet('emailBody', '登録用リンクです → {{ $guest_registration_url }}')
            ->assertSet('emailIncludeNoReplyFooter', true);
    }

    public function test_send_email_dispatches_mail_and_creates_system_log_row(): void
    {
        Mail::fake();
        [$booking, $msg] = $this->makeBookingWithGuestMessage();

        Livewire::test(ShowConversation::class, ['bookingId' => 900001])
            ->call('openEmailModal')
            ->set('emailTo', 'guest@real.example.com')
            ->set('emailSubject', '【Sample Stay】ゲスト情報登録のお願い')
            ->set('emailBody', '登録リンクは以下です: {{ $guest_registration_url }}')
            ->set('emailIncludeNoReplyFooter', true)
            ->call('sendEmail')
            ->assertSet('emailModalOpen', false)
            ->assertSet('emailSubject', '')
            ->assertSet('emailBody', '');

        Mail::assertSent(GuestRegistrationLinkMail::class, function (GuestRegistrationLinkMail $mail) {
            return $mail->hasTo('guest@real.example.com')
                && $mail->subjectLine === '【Sample Stay】ゲスト情報登録のお願い'
                && str_contains($mail->bodyRendered, '登録リンクは以下です:')
                && $mail->includeNoReplyFooter === true;
        });

        // booking.contact_email に保存されている
        $this->assertSame('guest@real.example.com', $booking->fresh()->contact_email);

        // 履歴にメール送信 system 行が追加されている (beds24 未投稿 = beds24_message_id null)
        $log = Message::query()
            ->where('beds24_booking_id', 900001)
            ->where('source', Message::SOURCE_SYSTEM)
            ->whereNotNull('emailed_to')
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('guest@real.example.com', $log->emailed_to);
        $this->assertSame('【Sample Stay】ゲスト情報登録のお願い', $log->email_subject);
        $this->assertNull($log->beds24_message_id);
        $this->assertTrue($log->isEmailSendLog());
    }

    public function test_send_email_validates_required_fields(): void
    {
        Mail::fake();
        $this->makeBookingWithGuestMessage();

        Livewire::test(ShowConversation::class, ['bookingId' => 900001])
            ->call('openEmailModal')
            ->set('emailTo', '')
            ->set('emailSubject', '')
            ->set('emailBody', '')
            ->call('sendEmail')
            ->assertHasErrors(['emailTo', 'emailSubject', 'emailBody']);

        Mail::assertNothingSent();
    }

    public function test_send_email_rejects_invalid_email(): void
    {
        Mail::fake();
        $this->makeBookingWithGuestMessage();

        Livewire::test(ShowConversation::class, ['bookingId' => 900001])
            ->call('openEmailModal')
            ->set('emailTo', 'not-an-email')
            ->set('emailSubject', '件名')
            ->set('emailBody', '本文')
            ->call('sendEmail')
            ->assertHasErrors(['emailTo' => 'email']);

        Mail::assertNothingSent();
    }
}
