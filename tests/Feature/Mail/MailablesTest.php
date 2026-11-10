<?php

namespace Tests\Feature\Mail;

use App\Mail\ContactAckMail;
use App\Mail\ContactSubmissionMail;
use App\Mail\GuestRegistrationLinkMail;
use App\Mail\ReservationConfirmationMail;
use App\Models\Booking;
use App\Models\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_submission_mail_renders_with_form_data(): void
    {
        $mail = new ContactSubmissionMail([
            'name' => '山田 太郎',
            'email' => 'yamada@example.com',
            'message' => "はじめまして。\nArea Aの 2 階を検討中です。",
            'room' => 'sample-location-2f',
        ]);

        $mail->assertSeeInHtml('新規お問い合わせ');
        $mail->assertSeeInHtml('山田 太郎');
        $mail->assertSeeInHtml('yamada@example.com');
        $mail->assertSeeInHtml('sample-location-2f');
        $mail->assertHasSubject('【お問い合わせ】山田 太郎 様より');
    }

    public function test_contact_ack_mail_renders_in_ja(): void
    {
        $mail = new ContactAckMail(
            guestName: '山田 太郎',
            guestMessage: 'wifi はありますか？',
            guestLocale: 'ja',
        );

        $mail->assertSeeInHtml('お問い合わせありがとうございます');
        $mail->assertSeeInHtml('山田 太郎 様');
        $mail->assertSeeInHtml('wifi はありますか？');
        $mail->assertHasSubject('お問い合わせありがとうございます｜Sample Stay');
    }

    public function test_contact_ack_mail_renders_in_en(): void
    {
        $mail = new ContactAckMail(
            guestName: 'John Doe',
            guestMessage: 'Do you have wifi?',
            guestLocale: 'en',
        );

        $mail->assertSeeInHtml('Thank you for reaching out');
        $mail->assertSeeInHtml('Dear John Doe');
        $mail->assertSeeInHtml('Do you have wifi?');
        $mail->assertHasSubject('Thank you for contacting Sample Stay');
    }

    public function test_contact_ack_falls_back_to_generic_greeting_when_name_empty(): void
    {
        $mail = new ContactAckMail('', 'test', 'en');
        $mail->assertSeeInHtml('Dear Guest');
    }

    public function test_reservation_confirmation_renders_booking_details(): void
    {
        $listing = Listing::create([
            'beds24_room_id' => 222006,
            'beds24_property_id' => 111005,
            'name' => 'test',
            'display_name' => '2階',
        ]);

        $booking = Booking::create([
            'beds24_booking_id' => 12345,
            'beds24_property_id' => 111005,
            'beds24_room_id' => 222006,
            'status' => Booking::STATUS_CONFIRMED,
            'first_name' => '花子',
            'last_name' => '田中',
            'arrival' => '2026-11-01',
            'departure' => '2026-11-03',
            'num_adult' => 2,
            'num_child' => 1,
            'raw_payload' => [],
            'fetched_at' => now(),
        ]);

        $mail = new ReservationConfirmationMail($booking, 'ja');

        $mail->assertSeeInHtml('ご予約が確定いたしました');
        $mail->assertSeeInHtml('田中 花子 様');
        $mail->assertSeeInHtml('12345');
        $mail->assertSeeInHtml('2026-11-01');
        $mail->assertSeeInHtml('2026-11-03');
        $mail->assertSeeInHtml('3'); // adults+children = 2+1
        $mail->assertHasSubject('ご予約完了のお知らせ｜Sample Stay');
    }

    public function test_guest_registration_link_mail_renders_body_and_footer(): void
    {
        $mail = new GuestRegistrationLinkMail(
            subjectLine: '【Sample Stay】ゲスト情報登録のお願い',
            bodyRendered: "山田様\n\n以下のリンクからご登録ください:\nhttps://admin.example.com/register/xxx",
            includeNoReplyFooter: true,
        );

        $mail->assertHasSubject('【Sample Stay】ゲスト情報登録のお願い');
        $mail->assertSeeInHtml('山田様');
        $mail->assertSeeInHtml('https://admin.example.com/register/xxx');
        $mail->assertSeeInHtml('返信いただいてもホストに届きません');
    }

    public function test_guest_registration_link_mail_omits_footer_when_disabled(): void
    {
        $mail = new GuestRegistrationLinkMail(
            subjectLine: 'テスト件名',
            bodyRendered: '本文のみ',
            includeNoReplyFooter: false,
        );

        $mail->assertSeeInHtml('本文のみ');
        $mail->assertDontSeeInHtml('返信いただいてもホストに届きません');
    }
}
