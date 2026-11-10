<?php

namespace App\Mail;

use App\Models\Booking;
use App\Services\GuestRegistration\GuestRegistrationTokenGenerator;
use App\Support\PublicUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * 予約完了メール (ゲスト宛)。
 *
 * Task #17 (公開HP 予約フォーム) の beds24 API 予約成立後に送信。
 * 予約 ID / 物件 / 日付 / 定員 / チェックイン方法案内などを含む。
 * ロケールは Task #17 でゲストが選択した言語 or Accept-Language。
 */
class ReservationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
        // Mailable::$locale と名前衝突するため guestLocale に
        public readonly string $guestLocale = 'ja',
    ) {}

    public function envelope(): Envelope
    {
        App::setLocale($this->guestLocale);

        return new Envelope(subject: $this->subjectFor($this->guestLocale));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reservation-confirmation',
            with: [
                'booking' => $this->booking,
                'locale' => $this->guestLocale,
                'chatUrl' => $this->chatUrl(),
                'registrationUrl' => $this->registrationUrl(),
            ],
        );
    }

    /**
     * 問い合わせ由来の予約のみ、GuestChannel の /chat/{token} URL を返す。
     * 直接予約 (GuestChannel 無し) では null。success ページと同じ条件。
     */
    private function chatUrl(): ?string
    {
        $channel = $this->booking->guestChannel;
        if ($channel === null) {
            return null;
        }

        return PublicUrl::route('chat.show', ['token' => $channel->token]);
    }

    /**
     * ゲスト情報登録フォーム URL。生成不能なら null。
     */
    private function registrationUrl(): ?string
    {
        $token = $this->booking->guestRegistrationToken
            ?? app(GuestRegistrationTokenGenerator::class)->ensureFor($this->booking);

        if ($token === null) {
            return null;
        }

        return PublicUrl::route('guest-registrations.show', ['token' => $token->token]);
    }

    private function subjectFor(string $locale): string
    {
        return match ($locale) {
            'en' => 'Your reservation is confirmed | Sample Stay',
            'es' => 'Reserva confirmada | Sample Stay',
            'zh' => '预订确认 | Sample Stay',
            'ko' => '예약 확정 안내 | Sample Stay',
            default => 'ご予約完了のお知らせ｜Sample Stay',
        };
    }
}
