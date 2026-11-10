<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * URL 剥奪 OTA (Agoda 経由 Booking.com 予約等) 向けに、ゲストが受け取った
 * beds24 messenger では URL がクリックできない状況を回避するため、ホストが
 * Conversation 詳細画面のモーダルから任意メアドへ本文をメール送信する。
 *
 * 本文は BodyRenderer で予約変数展開後の完成文をそのまま渡す想定 (Mailable 側
 * では素通し)。返信不可フッターの有無はモーダルのチェックボックスで制御し、
 * true なら view 側で末尾に定型注記を出す。
 */
class GuestRegistrationLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $subjectLine  メール件名 (ホストがモーダルで入力)
     * @param  string  $bodyRendered  変数展開済みの本文 (改行保持で送る)
     * @param  bool  $includeNoReplyFooter  「このメールには返信しないで」を末尾に表示
     */
    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyRendered,
        public readonly bool $includeNoReplyFooter = true,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.guest-registration-link',
            with: [
                'bodyRendered' => $this->bodyRendered,
                'includeNoReplyFooter' => $this->includeNoReplyFooter,
            ],
        );
    }
}
