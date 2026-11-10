<?php

namespace App\Http\Controllers;

use App\Services\Beds24\BookingUpserter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * beds24 Booking Webhook 受け口。
 *
 * beds24 dashboard の `Settings > Properties > Access > Booking webhooks` で
 * URL: `POST https://admin.example.com/api/beds24/webhook/bookings`
 * Custom Header: `X-Webhook-Secret: <config('beds24.webhook_secret')>`
 * を設定して使う。
 *
 * 予約新規/変更を即時 UPSERT。ゲスト情報登録トークンの生成と
 * 自動送信メッセージのスケジュールも同時に走る（BookingUpserter 側）。
 *
 * リトライ回避のため常に 200 or 4xx を早めに返す（500 は beds24 の自動再送対象）。
 */
class Beds24WebhookController extends Controller
{
    public function bookings(Request $request, BookingUpserter $upserter): JsonResponse
    {
        if (! $this->verifySecret($request)) {
            Log::warning('beds24 webhook auth failed', [
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
            ]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();

        // beds24 の実 payload（2026-09-20 実測）:
        //   { timeStamp, booking: {...}, infoItems, invoiceItems, messages, retries }
        // `booking` 単数キーがメイン。念のため以下 3 形式を許容:
        //   1) { booking: {...} }              ← 実測、標準
        //   2) { bookings: [{...}, ...] }      ← バッチ想定（未検証）
        //   3) { id, ... }                     ← 予約 payload 直渡し（旧仮説）
        // 認識できない shape は 400（beds24 側の再送は不要）。
        if (isset($payload['booking']) && is_array($payload['booking'])) {
            $items = [$payload['booking']];
        } elseif (isset($payload['bookings']) && is_array($payload['bookings']) && $payload['bookings'] !== []) {
            $items = $payload['bookings'];
        } elseif (isset($payload['id'])) {
            $items = [$payload];
        } else {
            Log::warning('beds24 webhook payload not recognized', [
                'payload_keys' => array_keys($payload),
            ]);

            return response()->json(['error' => 'invalid payload'], 400);
        }

        $processed = 0;
        $errors = [];

        foreach ($items as $item) {
            if (! is_array($item) || ! isset($item['id'])) {
                $errors[] = 'item missing id';

                continue;
            }

            try {
                $upserter->upsert($item);
                $processed++;
            } catch (Throwable $e) {
                Log::error('beds24 webhook upsert failed', [
                    'beds24_booking_id' => $item['id'],
                    'error' => $e->getMessage(),
                ]);
                $errors[] = 'upsert failed for id='.$item['id'];
            }
        }

        Log::info('beds24 webhook processed', [
            'processed' => $processed,
            'errors' => count($errors),
        ]);

        // 部分成功でも 200 を返す（成功分は反映済み。beds24 の再送は避けたい）。
        // 全件失敗は 500 で再送要求（BookingUpserter 側の一時障害を想定）。
        if ($processed === 0 && $errors !== []) {
            return response()->json(['error' => 'all items failed', 'details' => $errors], 500);
        }

        return response()->json([
            'processed' => $processed,
            'errors' => $errors,
        ]);
    }

    private function verifySecret(Request $request): bool
    {
        $expected = (string) config('beds24.webhook_secret');
        if ($expected === '') {
            return false;
        }

        $provided = (string) $request->header('X-Webhook-Secret', '');

        return hash_equals($expected, $provided);
    }
}
