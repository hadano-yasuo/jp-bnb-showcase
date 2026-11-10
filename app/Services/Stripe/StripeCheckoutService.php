<?php

namespace App\Services\Stripe;

use App\Models\PendingReservation;
use Stripe\Checkout\Session;
use Stripe\StripeClient;

/**
 * Stripe Checkout Session を作成する。
 *
 * 公開HP の直接予約フォーム (/reserve/direct) で、ReserveController がフォーム
 * バリデーション + PendingReservation 作成後にここを呼び、Stripe の決済ページへ
 * リダイレクトする。決済完了は webhook (checkout.session.completed) で受け、
 * Beds24BookingCreator が beds24 POST /bookings を発火する。
 *
 * JPY は zero-decimal currency なので金額は円単位の整数で渡す。
 */
class StripeCheckoutService
{
    public function __construct(
        private readonly StripeClient $client,
    ) {}

    /**
     * Checkout Session を作成し、作成した Session オブジェクトを返す。
     * 呼び出し側で Session->id を PendingReservation.stripe_session_id に保存し、
     * Session->url にリダイレクトする想定。
     *
     * @param  PendingReservation  $pending  stripe_session_id 未設定の pending 行
     * @param  string  $productName  Stripe 決済ページに表示する商品名 (「物件名 × 泊数」等)
     * @param  string  $successUrl  決済成功後のリダイレクト先 (jp-bnb の success ページ)
     * @param  string  $cancelUrl  決済キャンセル時のリダイレクト先 (jp-bnb の予約フォーム)
     * @param  string  $locale  Stripe 決済 UI の言語 (ja/en/es/zh/ko)
     */
    public function createSession(
        PendingReservation $pending,
        string $productName,
        string $successUrl,
        string $cancelUrl,
        string $locale,
    ): Session {
        $email = (string) ($pending->form_data['email'] ?? '');

        return $this->client->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => 'jpy',
                    'unit_amount' => $pending->amount_jpy,
                    'product_data' => [
                        'name' => $productName,
                    ],
                ],
            ]],
            'customer_email' => $email !== '' ? $email : null,
            'success_url' => $successUrl.'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'locale' => $this->mapLocale($locale),
            'metadata' => [
                'pending_reservation_id' => (string) $pending->id,
            ],
            'payment_intent_data' => [
                'metadata' => [
                    'pending_reservation_id' => (string) $pending->id,
                ],
            ],
        ]);
    }

    /**
     * jp-bnb のロケール (ja/en/es/zh/ko) を Stripe Checkout がサポートする
     * locale 文字列に変換。Stripe は zh を zh / zh-HK など複数形式を持つが、
     * デフォルト zh を採用。
     */
    private function mapLocale(string $locale): string
    {
        return match ($locale) {
            'ja' => 'ja',
            'en' => 'en',
            'es' => 'es',
            'zh' => 'zh',
            'ko' => 'ko',
            default => 'auto',
        };
    }
}
