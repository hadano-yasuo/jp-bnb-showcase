<?php

namespace App\Services\Beds24;

use App\Services\Beds24\Exceptions\Beds24Exception;
use Illuminate\Support\Carbon;

/**
 * beds24 の `/inventory/rooms/offers` から Offer 1 の宿泊料金を取得し、
 * 清掃費を加算した合計金額 (JPY, 整数) を返す。
 *
 * jp-bnb の価格モデル:
 *   PriceLabs → beds24 Offer 1 (= 自社サイト用の実売価格、割引・占有調整込み)
 *   清掃費は /properties roomTypes[].cleaningFee から取得して加算
 *
 * 価格の source of truth は Beds24。jp-bnb 側では割引ロジックを持たない
 * (運用のシンプルさ優先、必要になったら config を再導入)。
 *
 * 必要な beds24 scope: `read:inventory`, `read:properties`
 */
class Beds24PriceFetcher
{
    public function __construct(
        private readonly Beds24Client $client,
    ) {}

    /**
     * 指定 room × 日付範囲 × 人数の決済額を JPY (整数) で返す。
     *
     * @param  int  $propertyId  beds24 propertyId
     * @param  int  $roomId  beds24 roomId
     * @param  Carbon  $checkIn  チェックイン日
     * @param  Carbon  $checkOut  チェックアウト日 (exclusive)
     * @param  int  $numAdults  大人人数 (占有別料金の判定に必須、1以上)
     *
     * @throws Beds24Exception
     */
    public function totalPrice(int $propertyId, int $roomId, Carbon $checkIn, Carbon $checkOut, int $numAdults = 1): int
    {
        $base = $this->fetchBasePrice($propertyId, $roomId, $checkIn, $checkOut, $numAdults);
        $cleaning = $this->fetchCleaningFee($propertyId, $roomId);

        $total = (int) round($base + $cleaning);

        if ($total <= 0) {
            throw new Beds24Exception(
                message: 'beds24 の料金合計が 0 以下です',
                context: ['base' => $base, 'cleaning' => $cleaning, 'total' => $total],
            );
        }

        return $total;
    }

    /**
     * 清掃費を取得。/properties?includeAllRooms=true の roomTypes[].cleaningFee。
     * 1 booking あたり 1 回加算 (per-night ではない)。
     * 未設定なら 0 を返す。
     */
    private function fetchCleaningFee(int $propertyId, int $roomId): float
    {
        $response = $this->client->get('/properties', [
            'id' => $propertyId,
            'includeAllRooms' => 'true',
        ]);

        $rooms = $response['data'][0]['roomTypes'] ?? [];
        foreach ($rooms as $room) {
            if ((int) ($room['id'] ?? 0) === $roomId) {
                return (float) ($room['cleaningFee'] ?? 0);
            }
        }

        return 0.0;
    }

    /**
     * ベース料金 (Airbnb 相当) を取得。
     *   1. まず /inventory/rooms/offers から Offer 1 の price を取る
     *      (占有別調整済み、Beds24 側の計算が反映される)
     *   2. offers が空なら /inventory/rooms/calendar の price1 を日別合計
     *      (room の Offer 設定が未完全な場合の fallback)
     */
    private function fetchBasePrice(int $propertyId, int $roomId, Carbon $checkIn, Carbon $checkOut, int $numAdults): float
    {
        $offerResponse = $this->client->get('/inventory/rooms/offers', [
            'propertyId' => $propertyId,
            'roomId' => $roomId,
            'arrival' => $checkIn->format('Y-m-d'),
            'departure' => $checkOut->format('Y-m-d'),
            'numAdults' => max(1, $numAdults),
        ]);

        $offer = $this->pickOffer($offerResponse['data'][0]['offers'] ?? []);
        if ($offer !== null && isset($offer['price'])) {
            return (float) $offer['price'];
        }

        return $this->fetchCalendarSum($propertyId, $roomId, $checkIn, $checkOut);
    }

    /**
     * calendar から各日の price1 を合計する fallback。
     */
    private function fetchCalendarSum(int $propertyId, int $roomId, Carbon $checkIn, Carbon $checkOut): float
    {
        $endDate = $checkOut->copy()->subDay();

        $response = $this->client->get('/inventory/rooms/calendar', [
            'propertyId' => $propertyId,
            'roomId' => $roomId,
            'startDate' => $checkIn->format('Y-m-d'),
            'endDate' => $endDate->format('Y-m-d'),
            'includePrices' => 'true',
        ]);

        $calendar = $response['data'][0]['calendar'] ?? null;
        if (! is_array($calendar) || $calendar === []) {
            throw new Beds24Exception(
                message: 'beds24 で料金が取得できませんでした (offers / calendar いずれも空)',
                context: ['propertyId' => $propertyId, 'roomId' => $roomId],
            );
        }

        $total = 0.0;
        $cursor = $checkIn->copy();
        while ($cursor->lte($endDate)) {
            $date = $cursor->format('Y-m-d');
            $price = null;
            foreach ($calendar as $range) {
                if ($range['from'] <= $date && $date <= $range['to'] && isset($range['price1'])) {
                    $price = (float) $range['price1'];
                    break;
                }
            }
            if ($price === null) {
                throw new Beds24Exception(
                    message: "calendar に {$date} の price1 が無い",
                    context: ['calendar' => $calendar, 'date' => $date],
                );
            }
            $total += $price;
            $cursor->addDay();
        }

        return $total;
    }

    /**
     * Offer 1 (ベースライン) を優先選択。見つからなければ先頭。
     *
     * @param  list<array<string, mixed>>  $offers
     * @return array<string, mixed>|null
     */
    private function pickOffer(array $offers): ?array
    {
        foreach ($offers as $o) {
            if ((int) ($o['offerId'] ?? 0) === 1) {
                return $o;
            }
        }

        return $offers[0] ?? null;
    }
}
