<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * 問い合わせ受付の自動返信メール (ゲスト宛)。
 *
 * ゲストの locale (公開HP の SetPublicLocale で決まる) で内容を出し分ける。
 * Task #16 (問い合わせフォーム) から送信。
 */
class ContactAckMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  string  $guestName  ゲスト表示名 (空でも fallback 表示)
     * @param  string  $guestMessage  受け付けたメッセージ本文 (確認用にリキャップ)
     * @param  string  $guestLocale  ゲストの表示言語 (ja/en/es/zh/ko)。
     *                               Mailable::$locale と名前衝突するため guestLocale に。
     * @param  string  $chatUrl  /chat/{token} の絶対 URL (継続チャット導線)
     */
    public function __construct(
        public readonly string $guestName,
        public readonly string $guestMessage,
        public readonly string $guestLocale = 'ja',
        public readonly string $chatUrl = '',
    ) {}

    public function envelope(): Envelope
    {
        // Task #16 の呼び出し側で Mail::to()->locale($locale) すれば Laravel が
        // 自動で App::setLocale する。この Mailable 側は subject をロケール別に返すだけ。
        App::setLocale($this->guestLocale);

        return new Envelope(subject: $this->subjectFor($this->guestLocale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact-ack',
            with: [
                'guestName' => $this->guestName,
                'guestMessage' => $this->guestMessage,
                'chatUrl' => $this->chatUrl,
                'locale' => $this->guestLocale,
            ],
        );
    }

    private function subjectFor(string $locale): string
    {
        return match ($locale) {
            'en' => 'Thank you for contacting Sample Stay',
            'es' => 'Gracias por contactar con Sample Stay',
            'zh' => '感谢您联系 Sample Stay',
            'ko' => 'Sample Stay에 문의해 주셔서 감사합니다',
            default => 'お問い合わせありがとうございます｜Sample Stay',
        };
    }
}
