<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\GuestChannel;
use App\Models\Listing;
use App\Models\PendingReservation;
use App\Models\RoomBlock;
use App\Services\Beds24\Beds24PriceFetcher;
use App\Services\Stripe\StripeCheckoutService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 公開HP の直接予約フォーム。
 *
 * フロー (Phase 1 Stripe Checkout):
 *   1. フォーム送信 → バリデーション + 既存予約との重複チェック
 *   2. Beds24PriceFetcher で自社サイト用 rate の料金取得
 *   3. PendingReservation 作成 (form_data + amount_jpy)
 *   4. Stripe Checkout Session を作成し metadata.pending_reservation_id を仕込む
 *   5. ゲストを Stripe の決済ページへリダイレクト
 *   6. 決済完了 → StripeCheckoutController::webhook → Beds24BookingCreator が
 *      beds24 POST /bookings を発火、GuestChannel 継続/作成、メール送信
 *
 * 建物単位の複数部屋予約 (`_building:X:N`) は Phase 2 で対応予定。現在は
 * 単一部屋のみ Stripe 決済可能で、建物モード送信時はエラーで差し戻す。
 */
class ReserveController extends Controller
{
    public function __construct(
        private readonly Beds24PriceFetcher $priceFetcher,
        private readonly StripeCheckoutService $checkoutService,
    ) {}

    /** 建物単位の表示名。config/rooms.php の building_slug → 表示名。 */
    private const BUILDING_NAMES = [
        'sample-location' => 'Area A',
        'other-location' => 'Area B',
    ];

    /**
     * OTA (Airbnb / Booking.com) 経由の予約リクエスト用ランディングページ。
     * VPS 移行初期は直接予約フォーム (show) は Basic 認証で隠し、この OTA
     * ランディングを公開する運用。
     */
    public function ota(): View
    {
        return view('public.reserve.ota', [
            'rooms' => config('rooms'),
        ]);
    }

    public function show(Request $request): View
    {
        $inquiry = $this->resolveInquiry($request->query('inquiry'));
        $preselectedSlug = $inquiry?->room_slug;
        $preselectedBeds24Id = $preselectedSlug !== null
            ? (string) config('rooms.'.$preselectedSlug.'.beds24_room_id', '')
            : (string) $request->query('room', '');

        return view('public.reserve.show', [
            'rooms' => config('rooms'),
            'buildingOptions' => $this->buildBuildingOptions(),
            'inquiry' => $inquiry,
            'preselectedRoomSlug' => $preselectedSlug,
            'preselectedRoomId' => $preselectedBeds24Id !== '' ? $preselectedBeds24Id : null,
            'roomAvailability' => $this->buildRoomAvailability(),
        ]);
    }

    /**
     * 建物単位の複数部屋選択オプションを組み立てる。
     * value 例: `_building:other-location:3` (Area B建物・3 部屋)
     *
     * @return list<array{value: string, label: string, blocked: list<string>}>
     */
    private function buildBuildingOptions(): array
    {
        $rooms = config('rooms');
        $roomsByBuilding = [];
        foreach ($rooms as $slug => $room) {
            $bslug = (string) ($room['building_slug'] ?? '');
            if ($bslug === '') {
                continue;
            }
            $roomsByBuilding[$bslug][] = $slug;
        }

        $availability = $this->buildRoomAvailability();
        $options = [];

        foreach ($roomsByBuilding as $bslug => $slugs) {
            $total = count($slugs);
            if ($total < 2) {
                continue; // 1 部屋しかない建物は建物オプション不要
            }

            $buildingName = self::BUILDING_NAMES[$bslug] ?? ucfirst($bslug);

            // 2部屋〜total部屋 の選択肢
            for ($n = 2; $n <= $total; $n++) {
                $options[] = [
                    'value' => "_building:{$bslug}:{$n}",
                    'label' => "{$buildingName}建物 {$n} 部屋（同時利用）",
                    'blocked' => $this->buildingBlockedDates($slugs, $n, $availability),
                ];
            }
        }

        return $options;
    }

    /**
     * 建物単位の予約不可日リスト:
     * その日に「利用中の部屋数 > (total - required_n)」= 空き部屋が required_n 未満 の日。
     *
     * @param  list<string>  $slugs  建物の全部屋 slug
     * @param  int  $requiredN  必要な部屋数
     * @param  array<string, list<string>>  $availability  slug -> blocked dates
     * @return list<string>
     */
    private function buildingBlockedDates(array $slugs, int $requiredN, array $availability): array
    {
        $total = count($slugs);
        // date -> 予約中の部屋数
        $counts = [];
        foreach ($slugs as $slug) {
            foreach ($availability[$slug] ?? [] as $date) {
                $counts[$date] = ($counts[$date] ?? 0) + 1;
            }
        }

        $blocked = [];
        foreach ($counts as $date => $bookedCount) {
            $availableRooms = $total - $bookedCount;
            if ($availableRooms < $requiredN) {
                $blocked[] = $date;
            }
        }
        sort($blocked);

        return $blocked;
    }

    /**
     * 各部屋の予約不可日を返す (bookings + beds24 の Blackout = RoomBlock)。
     * カレンダー UI (flatpickr) が選択不可にするために使う。
     *
     * - Booking: arrival〜departure-1 日を blocked (departure 日は次ゲスト arrival 可)
     * - RoomBlock: beds24 のカレンダーで availability=false の日 (手動 Blackout 含む)
     *   → bookings と重複する日もあるが union で統合
     *
     * @return array<string, list<string>> room_slug => ['YYYY-MM-DD', ...]
     */
    private function buildRoomAvailability(): array
    {
        $availability = [];
        $rooms = config('rooms');
        $roomIds = array_values(array_filter(array_map(
            static fn (array $r): int => (int) ($r['beds24_room_id'] ?? 0),
            $rooms,
        )));

        $bookings = Booking::query()
            ->whereIn('beds24_room_id', $roomIds)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->where('departure', '>=', now()->toDateString())
            ->get(['beds24_room_id', 'arrival', 'departure']);

        $blockedByRoomId = [];
        foreach ($bookings as $b) {
            if ($b->beds24_room_id === null || $b->arrival === null || $b->departure === null) {
                continue;
            }
            $cursor = $b->arrival->copy();
            while ($cursor->lt($b->departure)) {
                $blockedByRoomId[$b->beds24_room_id][] = $cursor->format('Y-m-d');
                $cursor->addDay();
            }
        }

        // beds24 の Blackout (RoomBlock) も加算。booking と重複は array_unique で吸収
        $blocks = RoomBlock::query()
            ->whereIn('beds24_room_id', $roomIds)
            ->where('date', '>=', now()->toDateString())
            ->get(['beds24_room_id', 'date']);
        foreach ($blocks as $block) {
            $blockedByRoomId[$block->beds24_room_id][] = $block->date->format('Y-m-d');
        }

        foreach ($rooms as $slug => $room) {
            $roomId = (int) ($room['beds24_room_id'] ?? 0);
            $dates = $blockedByRoomId[$roomId] ?? [];
            sort($dates);
            $availability[$slug] = array_values(array_unique($dates));
        }

        return $availability;
    }

    public function store(Request $request): RedirectResponse
    {
        $roomSlugs = array_keys(config('rooms'));
        $buildingSlugs = array_map(fn (array $o): string => $o['value'], $this->buildBuildingOptions());
        $allValidSlugs = array_merge($roomSlugs, $buildingSlugs);

        $validated = $request->validate([
            'room_slug' => ['required', 'string', 'in:'.implode(',', $allValidSlugs)],
            'arrival' => ['required', 'date', 'after:today'],
            'departure' => ['required', 'date', 'after:arrival'],
            'num_adult' => ['required', 'integer', 'min:1', 'max:30'],
            'num_child' => ['nullable', 'integer', 'min:0', 'max:15'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'inquiry_token' => ['nullable', 'string', 'size:48'],
            // キャンセルポリシー (7日前まで全額返金、以降返金不可) への同意
            'policy_agreed' => ['required', 'accepted'],
        ]);

        $isBuildingMode = str_starts_with($validated['room_slug'], '_building:');
        if ($isBuildingMode) {
            // Phase 2 で対応予定。複数部屋決済・複数 beds24 予約成立の統合が必要
            return back()
                ->withInput()
                ->withErrors([
                    'room_slug' => '建物単位の複数部屋予約は現在オンライン決済に未対応です。お手数ですがお問い合わせフォームからご連絡ください。',
                ]);
        }

        $room = config('rooms.'.$validated['room_slug']);
        $roomId = (int) ($room['beds24_room_id'] ?? 0);
        $propertyId = $this->resolvePropertyId($roomId);

        if ($roomId === 0 || $propertyId === 0) {
            return back()
                ->withInput()
                ->withErrors([
                    'room_slug' => 'リスティング情報が見つかりませんでした。',
                ]);
        }

        if ($this->hasBookingOverlap($roomId, $validated['arrival'], $validated['departure'])) {
            return back()
                ->withInput()
                ->withErrors([
                    'arrival' => 'ご希望の期間はすでに予約が入っています。カレンダーの空き日をご確認ください。',
                ]);
        }

        try {
            $amount = $this->priceFetcher->totalPrice(
                propertyId: $propertyId,
                roomId: $roomId,
                checkIn: Carbon::parse($validated['arrival']),
                checkOut: Carbon::parse($validated['departure']),
                numAdults: (int) $validated['num_adult'],
            );
        } catch (Throwable $e) {
            Log::error('reserve: beds24 price fetch failed', [
                'error' => $e->getMessage(),
                'room_slug' => $validated['room_slug'],
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'arrival' => '料金の取得に失敗しました。しばらくしてから再度お試しください。',
                ]);
        }

        $channel = $this->resolveInquiry($validated['inquiry_token'] ?? null);
        $locale = App::getLocale();

        $pending = PendingReservation::create([
            'form_data' => array_merge($validated, ['locale' => $locale]),
            'amount_jpy' => $amount,
            'status' => PendingReservation::STATUS_PENDING,
            'guest_channel_id' => $channel?->id,
            'expires_at' => now()->addHours(24),
        ]);

        try {
            $session = $this->checkoutService->createSession(
                pending: $pending,
                productName: sprintf('%s (%s 〜 %s)', $room['name'], $validated['arrival'], $validated['departure']),
                successUrl: route('reserve.success'),
                cancelUrl: route('reserve.direct'),
                locale: $locale,
            );
            $pending->update(['stripe_session_id' => $session->id]);
        } catch (Throwable $e) {
            Log::error('reserve: stripe session creation failed', [
                'error' => $e->getMessage(),
                'pending_id' => $pending->id,
            ]);
            $pending->update([
                'status' => PendingReservation::STATUS_FAILED,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ]);

            return back()
                ->withInput()
                ->withErrors([
                    'arrival' => '決済ページの初期化に失敗しました。しばらくしてから再度お試しください。',
                ]);
        }

        return redirect()->away((string) $session->url);
    }

    /**
     * beds24_room_id から propertyId を解決する。bookings テーブルの
     * (beds24_room_id, beds24_property_id) のペアから拾う実装 (Listing でもよいが MVP は booking から)。
     */
    private function resolvePropertyId(int $beds24RoomId): int
    {
        $listing = Listing::query()->where('beds24_room_id', $beds24RoomId)->first();

        return $listing !== null ? (int) ($listing->beds24_property_id ?? 0) : 0;
    }

    /**
     * 指定 room + 期間に既存予約が重なるかチェック。
     * 重複条件: existing.arrival < new.departure && existing.departure > new.arrival
     * (departure 日は退出日なので重複扱いしない = 上式で自然に OK)
     *
     * whereDate() で date のみ比較。SQLite (テスト) が date を TEXT で持ち timestamp との
     * 文字列比較でズレるのを避けるため、明示的な date キャストが必要。
     */
    private function hasBookingOverlap(int $beds24RoomId, string $arrival, string $departure): bool
    {
        return Booking::query()
            ->where('beds24_room_id', $beds24RoomId)
            ->whereNotIn('status', ['cancelled', 'canceled'])
            ->whereDate('arrival', '<', $departure)
            ->whereDate('departure', '>', $arrival)
            ->exists();
    }

    private function resolveInquiry(?string $token): ?GuestChannel
    {
        if ($token === null || $token === '') {
            return null;
        }

        return GuestChannel::query()
            ->where('token', $token)
            ->where('expires_at', '>', now())
            ->first();
    }
}
