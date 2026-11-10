<?php

namespace App\Http\Controllers;

use App\Models\PendingReservation;
use App\Services\Beds24\Beds24BookingCreator;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Event as StripeEvent;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook as StripeWebhook;

/**
 * Stripe Checkout の 3 エンドポイント:
 *  - success: 決済成功後の戻り先。完了ページを表示
 *  - cancel:  決済キャンセル時の戻り先。予約フォームへ戻す
 *  - webhook: Stripe → jp-bnb の非同期通知。checkout.session.completed で予約成立
 */
class StripeCheckoutController extends Controller
{
    public function __construct(
        private readonly Beds24BookingCreator $bookingCreator,
    ) {}

    /**
     * 決済完了後の戻り先。session_id を元に PendingReservation を解決して
     * 完了ページを表示する。webhook が先に走って予約成立していることが期待されるが、
     * webhook が遅延しているケースもあるので status を見て案内文を変える。
     */
    public function success(Request $request): View
    {
        $sessionId = (string) $request->query('session_id', '');
        $pending = $sessionId !== ''
            ? PendingReservation::where('stripe_session_id', $sessionId)->first()
            : null;

        $chatUrl = null;
        if ($pending !== null) {
            $channel = $pending->guestChannel()->first();
            $chatUrl = $channel !== null ? route('chat.show', ['token' => $channel->token]) : null;
        }

        return view('public.reserve.success', [
            'pending' => $pending,
            'chatUrl' => $chatUrl,
        ]);
    }

    public function cancel(): RedirectResponse
    {
        return redirect()->route('reserve.direct');
    }

    /**
     * Stripe webhook。署名検証後に checkout.session.completed イベントだけ処理し、
     * Beds24BookingCreator::fulfill で予約成立まで進める。
     */
    public function webhook(Request $request): JsonResponse
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');
        $secret = (string) config('services.stripe.webhook_secret', '');

        if ($secret === '') {
            Log::error('Stripe webhook: STRIPE_WEBHOOK_SECRET 未設定');

            return response()->json(['error' => 'misconfigured'], 500);
        }

        try {
            $event = StripeWebhook::constructEvent($payload, $signature, $secret);
        } catch (SignatureVerificationException $e) {
            Log::warning('Stripe webhook: 署名検証失敗', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'invalid signature'], 400);
        }

        if ($event->type !== StripeEvent::CHECKOUT_SESSION_COMPLETED) {
            return response()->json(['ok' => true, 'ignored' => $event->type]);
        }

        /** @var StripeSession $session */
        $session = $event->data->object;
        $pendingId = (int) ($session->metadata['pending_reservation_id'] ?? 0);
        $pending = $pendingId > 0 ? PendingReservation::find($pendingId) : null;

        if ($pending === null) {
            Log::warning('Stripe webhook: pending_reservation 見つからず', [
                'session_id' => $session->id,
                'pending_id' => $pendingId,
            ]);

            return response()->json(['error' => 'pending not found'], 404);
        }

        // 二重受信対策で session_id を保存 (初回のみ)
        if ($pending->stripe_session_id === null) {
            $pending->update(['stripe_session_id' => (string) $session->id]);
        }

        $this->bookingCreator->fulfill($pending);

        return response()->json(['ok' => true]);
    }
}
