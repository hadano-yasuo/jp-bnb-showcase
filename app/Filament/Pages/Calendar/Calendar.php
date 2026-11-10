<?php

namespace App\Filament\Pages\Calendar;

use App\Jobs\Beds24\SyncBookingsJob;
use App\Jobs\Beds24\SyncRoomBlocksJob;
use App\Models\Booking;
use App\Models\Listing;
use App\Models\RoomBlock;
use App\Services\Calendar\JapanHolidayProvider;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Throwable;

/**
 * リスティング × 日付のカレンダー。/admin/calendar。
 *
 * Airbnb のホストカレンダー相当。全予約とブロックを横スクロールで俯瞰する。
 * - 縦: 全リスティング（Listing::is_active=true）
 * - 横: startDate を左端に days 日分（横スクロール）
 * - 予約バー: arrival〜departure を absolute 配置、OTA を色分け、クリックで会話画面へ
 * - ブロックバー: room_blocks の availability=false を連続日付にまとめて表示（クリック不可）
 * - キャンセルはデフォルト非表示、トグルで表示切替（ダッシュ枠 + 取り消し線で区別）
 *
 * @property-read Collection<int, Listing> $listings
 * @property-read Collection<int, Booking> $bookings
 * @property-read Collection<int, RoomBlock> $roomBlocks
 * @property-read array<int, array{date: Carbon, isToday: bool, isPast: bool, isWeekend: bool, isHoliday: bool, holidayName: ?string}> $dateColumns
 */
class Calendar extends Page
{
    protected string $view = 'filament.pages.calendar.index';

    protected static ?string $slug = 'calendar';

    protected static ?string $navigationLabel = 'カレンダー';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?int $navigationSort = 10;

    /**
     * URL に載せる基準日（左端）。デフォルトは今日の前日
     * （本日を「左から2番目」に置くため）。
     */
    #[Url]
    public ?string $start = null;

    /**
     * キャンセル予約を表示するか。URL に永続化してリロードで維持。
     */
    #[Url]
    public bool $showCancelled = false;

    /**
     * 表示日数。30 日（1画面 = 1か月弱を横スクロールで俯瞰）。
     */
    public int $days = 30;

    public function mount(): void
    {
        if ($this->start === null) {
            $this->start = $this->defaultStartDate()->toDateString();
        }
    }

    private function defaultStartDate(): Carbon
    {
        return Carbon::today()->subDays(2);
    }

    public function getTitle(): string
    {
        return 'カレンダー';
    }

    public function getHeading(): string
    {
        return 'カレンダー';
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return ['カレンダー'];
    }

    public function shiftPrev(): void
    {
        $this->start = $this->startDate()->subDays($this->days)->toDateString();
    }

    public function shiftNext(): void
    {
        $this->start = $this->startDate()->addDays($this->days)->toDateString();
    }

    public function resetToday(): void
    {
        $this->start = $this->defaultStartDate()->toDateString();
    }

    public function toggleCancelled(): void
    {
        $this->showCancelled = ! $this->showCancelled;
    }

    /**
     * beds24 の予約とブロックをまとめて同期する。
     */
    public function sync(): void
    {
        try {
            dispatch_sync(new SyncBookingsJob);
            dispatch_sync(new SyncRoomBlocksJob);
            Notification::make()->title('最新予約とブロックを取得しました')->success()->send();
        } catch (Throwable $e) {
            Notification::make()
                ->title('同期に失敗しました')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function startDate(): Carbon
    {
        return Carbon::parse($this->start ?? $this->defaultStartDate()->toDateString())->startOfDay();
    }

    public function endDate(): Carbon
    {
        return $this->startDate()->copy()->addDays($this->days - 1);
    }

    /**
     * カレンダーに並べる日付列。
     *
     * @return array<int, array{date: Carbon, isToday: bool, isPast: bool, isWeekend: bool, isHoliday: bool, holidayName: ?string}>
     */
    public function getDateColumnsProperty(): array
    {
        $today = Carbon::today();
        $holidays = app(JapanHolidayProvider::class)->between($this->startDate(), $this->endDate());
        $columns = [];
        for ($i = 0; $i < $this->days; $i++) {
            $date = $this->startDate()->copy()->addDays($i);
            $key = $date->toDateString();
            $columns[] = [
                'date' => $date,
                'isToday' => $date->isSameDay($today),
                'isPast' => $date->lt($today),
                'isWeekend' => $date->isWeekend(),
                'isHoliday' => isset($holidays[$key]),
                'holidayName' => $holidays[$key] ?? null,
            ];
        }

        return $columns;
    }

    /**
     * @return Collection<int, Listing>
     */
    public function getListingsProperty(): Collection
    {
        return Listing::query()
            ->with('building')
            ->where('is_active', true)
            ->get()
            ->sortBy([
                fn (Listing $a, Listing $b) => ($a->building->name ?? '') <=> ($b->building->name ?? ''),
                fn (Listing $a, Listing $b) => ($a->display_name ?? $a->name) <=> ($b->display_name ?? $b->name),
            ])
            ->values();
    }

    /**
     * 表示範囲に arrival/departure が重なる予約。
     * キャンセルは showCancelled=true のときのみ含む。
     *
     * @return Collection<int, Booking>
     */
    public function getBookingsProperty(): Collection
    {
        $query = Booking::query()
            ->whereNotNull('arrival')
            ->whereNotNull('departure')
            ->where('arrival', '<', $this->endDate()->copy()->addDay()->toDateString())
            ->where('departure', '>', $this->startDate()->toDateString());

        if (! $this->showCancelled) {
            $query->where('status', '!=', Booking::STATUS_CANCELLED);
        }

        return $query->get();
    }

    /**
     * 表示範囲に含まれる room_blocks のレコード。
     *
     * @return Collection<int, RoomBlock>
     */
    public function getRoomBlocksProperty(): Collection
    {
        return RoomBlock::query()
            ->whereBetween('date', [$this->startDate()->toDateString(), $this->endDate()->toDateString()])
            ->get();
    }

    /**
     * 1リスティングに紐づく有効予約（キャンセル除く）のセグメント。
     *
     * @return array<int, array{booking: Booking, offsetDays: int, spanDays: int, clippedLeft: bool, clippedRight: bool}>
     */
    public function activeBookingsFor(Listing $listing): array
    {
        return $this->segmentsFor($listing, cancelledOnly: false);
    }

    /**
     * 1リスティングに紐づくキャンセル予約を、貪欲アルゴリズムで
     * 重ならないサブレーンに割り当てて返す。showCancelled=false のときは空配列。
     *
     * 有効予約と同じ縦位置に重ねて描画すると視覚的に隠れてしまうため、
     * キャンセルは行下部の専用エリアに配置し、キャンセル同士が重なる場合は
     * サブレーンを縦に増やして情報を失わないようにする。
     *
     * @return array<int, array<int, array{booking: Booking, offsetDays: int, spanDays: int, clippedLeft: bool, clippedRight: bool}>>
     */
    public function cancelledLanesFor(Listing $listing): array
    {
        if (! $this->showCancelled) {
            return [];
        }

        $segments = $this->segmentsFor($listing, cancelledOnly: true);
        usort($segments, static fn (array $a, array $b): int => $a['offsetDays'] <=> $b['offsetDays']);

        $lanes = [];
        foreach ($segments as $seg) {
            $placed = false;
            foreach ($lanes as $idx => $lane) {
                $last = end($lane);
                $lastEnd = $last['offsetDays'] + $last['spanDays'];
                if ($seg['offsetDays'] >= $lastEnd) {
                    $lanes[$idx][] = $seg;
                    $placed = true;
                    break;
                }
            }
            if (! $placed) {
                $lanes[] = [$seg];
            }
        }

        return $lanes;
    }

    /**
     * @return array<int, array{booking: Booking, offsetDays: int, spanDays: int, clippedLeft: bool, clippedRight: bool}>
     */
    private function segmentsFor(Listing $listing, bool $cancelledOnly): array
    {
        $start = $this->startDate();
        $end = $this->endDate();

        $segments = [];
        foreach ($this->bookings as $booking) {
            if ($booking->beds24_room_id !== $listing->beds24_room_id) {
                continue;
            }
            $isCancelled = $booking->status === Booking::STATUS_CANCELLED;
            if ($cancelledOnly ? ! $isCancelled : $isCancelled) {
                continue;
            }

            /** @var Carbon $arrival */
            $arrival = $booking->arrival;
            /** @var Carbon $departure */
            $departure = $booking->departure;

            $visibleStart = $arrival->lt($start) ? $start : $arrival;
            $visibleEnd = $departure->gt($end->copy()->addDay()) ? $end->copy()->addDay() : $departure;

            $offsetDays = (int) $start->diffInDays($visibleStart, false);
            $spanDays = (int) $visibleStart->diffInDays($visibleEnd, false);
            if ($spanDays <= 0) {
                continue;
            }

            $segments[] = [
                'booking' => $booking,
                'offsetDays' => $offsetDays,
                'spanDays' => $spanDays,
                'clippedLeft' => $arrival->lt($start),
                'clippedRight' => $departure->gt($end->copy()->addDay()),
            ];
        }

        return $segments;
    }

    /**
     * 1リスティングに紐づくブロックセグメント（連続日付を1バーに集約）。
     *
     * beds24 の availability=false は「実予約による埋まり」も含むので、
     * 有効予約（キャンセル除く、black を含む）が重なる日は除外する。
     *
     * @return array<int, array{offsetDays: int, spanDays: int, clippedLeft: bool, clippedRight: bool}>
     */
    public function blocksFor(Listing $listing): array
    {
        $start = $this->startDate();
        $end = $this->endDate();

        $bookingCoverage = $this->bookingCoverageFor($listing);

        $dates = $this->roomBlocks
            ->where('beds24_room_id', $listing->beds24_room_id)
            ->pluck('date')
            ->filter(function (Carbon $date) use ($bookingCoverage): bool {
                return ! isset($bookingCoverage[$date->toDateString()]);
            })
            ->sortBy(fn (Carbon $d) => $d->toDateString())
            ->values();

        $segments = [];
        $rangeStart = null;
        $rangeEnd = null;

        foreach ($dates as $date) {
            if ($rangeStart === null || $rangeEnd === null) {
                $rangeStart = $date;
                $rangeEnd = $date;

                continue;
            }
            if ($date->toDateString() === $rangeEnd->copy()->addDay()->toDateString()) {
                $rangeEnd = $date;

                continue;
            }
            $segments[] = $this->buildBlockSegment($rangeStart, $rangeEnd, $start, $end);
            $rangeStart = $date;
            $rangeEnd = $date;
        }
        if ($rangeStart !== null && $rangeEnd !== null) {
            $segments[] = $this->buildBlockSegment($rangeStart, $rangeEnd, $start, $end);
        }

        return $segments;
    }

    /**
     * @return array<string, true> date-string キーで有効予約が覆う日
     */
    private function bookingCoverageFor(Listing $listing): array
    {
        $coverage = [];
        foreach ($this->bookings as $booking) {
            if ($booking->beds24_room_id !== $listing->beds24_room_id) {
                continue;
            }
            if ($booking->status === Booking::STATUS_CANCELLED) {
                continue;
            }
            /** @var Carbon $arrival */
            $arrival = $booking->arrival;
            /** @var Carbon $departure */
            $departure = $booking->departure;
            $cursor = $arrival->copy();
            while ($cursor->lt($departure)) {
                $coverage[$cursor->toDateString()] = true;
                $cursor->addDay();
            }
        }

        return $coverage;
    }

    /**
     * @return array{offsetDays: int, spanDays: int, clippedLeft: bool, clippedRight: bool}
     */
    private function buildBlockSegment(Carbon $rangeStart, Carbon $rangeEnd, Carbon $start, Carbon $end): array
    {
        // ブロックは day-set。予約と揃えるため arrival=最初のブロック日, departure=最後の翌日 とみなす。
        $blockArrival = $rangeStart;
        $blockDeparture = $rangeEnd->copy()->addDay();

        $visibleStart = $blockArrival->lt($start) ? $start : $blockArrival;
        $visibleEnd = $blockDeparture->gt($end->copy()->addDay()) ? $end->copy()->addDay() : $blockDeparture;

        return [
            'offsetDays' => (int) $start->diffInDays($visibleStart, false),
            'spanDays' => (int) $visibleStart->diffInDays($visibleEnd, false),
            'clippedLeft' => $blockArrival->lt($start),
            'clippedRight' => $blockDeparture->gt($end->copy()->addDay()),
        ];
    }

    /**
     * OTA（channel）ごとの色クラス。凡例と bar 描画で共用する。
     *
     * black ステータス（beds24 で予約をブロックする用途）は自社運用では
     * OTA と関係ない自主的な閉塞なので、channel を無視して「ブロック」に
     * 寄せる。未知チャネル（Airbnb/Booking/直接以外）は防御的に灰色＋
     * 「その他」ラベルにするが、現在の運用では発生しない想定。
     *
     * @return array{bg: string, border: string, label: string}
     */
    public function bookingStyle(Booking $booking): array
    {
        if ($booking->status === Booking::STATUS_BLACK) {
            return ['bg' => 'bg-gray-500', 'border' => 'border-gray-600', 'label' => 'ブロック'];
        }

        return match (strtolower($booking->channel ?? '')) {
            'airbnb' => ['bg' => 'bg-rose-500', 'border' => 'border-rose-600', 'label' => 'Airbnb'],
            'booking' => ['bg' => 'bg-blue-600', 'border' => 'border-blue-700', 'label' => 'Booking.com'],
            'direct' => ['bg' => 'bg-teal-600', 'border' => 'border-teal-700', 'label' => '直接'],
            default => ['bg' => 'bg-gray-500', 'border' => 'border-gray-600', 'label' => 'その他'],
        };
    }

    /**
     * バー内に表示する人数（大人+子供）。
     */
    public function guestCount(Booking $booking): int
    {
        return (int) ($booking->num_adult ?? 0) + (int) ($booking->num_child ?? 0);
    }

    /**
     * 凡例に出すチャネル一覧。
     *
     * @return array<int, array{bg: string, border: string, label: string}>
     */
    public function getChannelLegendProperty(): array
    {
        return [
            ['bg' => 'bg-rose-500', 'border' => 'border-rose-600', 'label' => 'Airbnb'],
            ['bg' => 'bg-blue-600', 'border' => 'border-blue-700', 'label' => 'Booking.com'],
            ['bg' => 'bg-teal-600', 'border' => 'border-teal-700', 'label' => '直接'],
            ['bg' => 'bg-gray-500', 'border' => 'border-gray-600', 'label' => 'ブロック'],
        ];
    }
}
