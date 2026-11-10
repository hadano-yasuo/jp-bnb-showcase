<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 自動送信ルールの配信を直接予約ゲスト (api_source=direct/Direct) 宛てにメールで送る。
 * OTA ゲスト (Airbnb / Booking.com) は OTA のメッセンジャー経由で送るのが本線のため使わない。
 *
 * 本文は BodyRenderer で展開済みの完成文 (翻訳が入る場合は訳文) を素通し。
 * $chatUrl が渡されると末尾に「チャットを開く」セクションが表示される。
 */
class AutoMessageMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyRendered,
        public readonly string $guestLocale,
        public readonly ?string $chatUrl = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auto-message',
            with: [
                'bodyRendered' => $this->bodyRendered,
                'locale' => $this->guestLocale,
                'chatUrl' => $this->chatUrl,
            ],
        );
    }
}
