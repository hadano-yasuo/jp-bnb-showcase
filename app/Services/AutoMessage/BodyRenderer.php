<?php

namespace App\Services\AutoMessage;

use App\Models\Booking;
use App\Services\GuestRegistration\GuestRegistrationTokenGenerator;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Blade;

/**
 * 本文（Blade 記法）を予約コンテキストで展開する。
 *
 * 自動送信ルールと定型文の両方で使う。利用可能変数一覧は
 * availableVariables() を単一の出典として使用する（Filament の変数
 * ピッカー UI にも同じリストを渡す）。
 */
class BodyRenderer
{
    /**
     * 変数ピッカーで選択可能な placeholder 一覧。
     * key: 挿入テキスト（Blade 記法）, value: 表示ラベル
     *
     * @return array<string, string>
     */
    public static function availableVariables(): array
    {
        return [
            '{{ $guest_first_name }}' => 'ゲスト名（名）',
            '{{ $guest_last_name }}' => 'ゲスト名（姓）',
            '{{ $guest_full_name }}' => 'ゲスト名（フル）',
            '{{ $arrival }}' => 'チェックイン日',
            '{{ $departure }}' => 'チェックアウト日',
            '{{ $num_adult }}' => '大人人数',
            '{{ $num_child }}' => '子供人数',
            '{{ $listing->display_name }}' => '部屋名',
            '{{ $listing->wifi_password }}' => 'Wi-Fi パスワード',
            '{{ $guest_registration_url }}' => 'ゲスト登録用URL',
        ];
    }

    /**
     * ゲスト名が未取得の場合に差し込むフォールバック文字列。
     * 「ゲストさん」→ en:"Hello, guest." / zh:"客人,你好。" / ko:"손님, 안녕하세요。"
     * のように hospitality 文脈で自然に翻訳されるため採用。
     * （「お客様」は "customer/client" に訳され商取引トーンで民泊に不自然）
     */
    private const EMPTY_NAME_FALLBACK = 'ゲスト';

    /**
     * @param  string|null  $locale  日付の表記ロケール。null は 'ja' 扱い。
     */
    public function render(string $body, Booking $booking, ?string $locale = null): string
    {
        $listing = $booking->listing;
        $locale = $locale ?: 'ja';

        $first = (string) ($booking->first_name ?? '');
        $last = (string) ($booking->last_name ?? '');
        $full = trim($last.' '.$first);

        $context = [
            'booking' => $booking,
            'listing' => $listing,
            'guest_first_name' => $first !== '' ? $first : self::EMPTY_NAME_FALLBACK,
            'guest_last_name' => $last !== '' ? $last : self::EMPTY_NAME_FALLBACK,
            'guest_full_name' => $full !== '' ? $full : self::EMPTY_NAME_FALLBACK,
            'arrival' => $booking->arrival?->locale($locale)->isoFormat('LL') ?? '',
            'departure' => $booking->departure?->locale($locale)->isoFormat('LL') ?? '',
            'num_adult' => (int) ($booking->num_adult ?? 0),
            'num_child' => (int) ($booking->num_child ?? 0),
            'guest_registration_url' => $this->guestRegistrationUrl($booking),
        ];

        return Blade::render($body, $context);
    }

    /**
     * ゲスト登録フォームの絶対URL。トークンがなければ生成する。
     * 生成に失敗（キャンセル済み等）した場合は空文字列。
     */
    private function guestRegistrationUrl(Booking $booking): string
    {
        $token = $booking->guestRegistrationToken
            ?? app(GuestRegistrationTokenGenerator::class)->ensureFor($booking);

        if ($token === null) {
            return '';
        }

        return PublicUrl::route('guest-registrations.show', ['token' => $token->token]);
    }
}
