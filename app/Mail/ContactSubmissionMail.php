<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 問い合わせフォーム送信時にホスト側 (自社) に届く通知メール。
 *
 * Task #16 (問い合わせフォーム) で ContactController から実際に送る。
 * ここではメール本体 (subject / view / data 束ね) のみ提供。
 */
class ContactSubmissionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $data  フォーム項目 (name/email/message/room 等)
     * @param  string  $adminUrl  Filament の GuestChannel 詳細 URL
     * @param  string|null  $subjectOverride  件名を上書きしたい場合に指定 (例: 直接予約の成立通知)
     * @param  string|null  $headline  本文冒頭見出しを上書きしたい場合に指定 (例: '新規予約')
     */
    public function __construct(
        public readonly array $data,
        public readonly string $adminUrl = '',
        public readonly ?string $subjectOverride = null,
        public readonly ?string $headline = null,
    ) {}

    public function envelope(): Envelope
    {
        // 呼び出し側は $data['氏名'] (日本語キー) で名前を渡す (Beds24BookingCreator /
        // ContactController 共通)。英語キー 'name' は後方互換のため fallback で残す。
        $guestName = $this->data['氏名']
            ?? $this->data['name']
            ?? '匿名';
        $email = $this->data['メール']
            ?? $this->data['email']
            ?? null;

        return new Envelope(
            subject: $this->subjectOverride
                ?? '【お問い合わせ】'.$guestName.' 様より',
            replyTo: $email !== null ? [$email] : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-submission',
            with: [
                'data' => $this->data,
                'adminUrl' => $this->adminUrl,
                'headline' => $this->headline ?? '新規お問い合わせ',
            ],
        );
    }
}
