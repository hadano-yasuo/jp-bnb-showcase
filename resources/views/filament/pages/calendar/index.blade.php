@php
    /** @var \App\Filament\Pages\Calendar\Calendar $this */
    $dayWidth = 64;
    $activeAreaHeight = 40;         // 有効予約バー領域（Airbnb 相当より少し細身）
    $activeBarInsetY = 4;           // top/bottom の余白（activeAreaHeight - 2*insetY = バー高）
    $cancelledLaneHeight = 10;      // キャンセルバー1本の高さ
    $cancelledLaneGap = 2;          // キャンセルレーン間の隙間
    $cancelledLanePitch = $cancelledLaneHeight + $cancelledLaneGap;
    $dateColumns = $this->dateColumns;
    $listings = $this->listings;
    // ラベル列の幅は表示中の最大リスティング名に自動フィット。
    // mb_strwidth は CJK を 2 として数えるため、半角換算長 × 実測寄りの単価で近似する。
    $maxLabelPx = 0;
    foreach ($listings as $listing) {
        $primary = $listing->display_name ?? $listing->name ?? '';
        $sub = $listing->building?->name ?? '';
        $primaryPx = (int) ceil(mb_strwidth($primary) / 2) * 15;
        $subPx = (int) ceil(mb_strwidth($sub) / 2) * 13;
        $maxLabelPx = max($maxLabelPx, $primaryPx, $subPx);
    }
    $labelWidth = max(120, min(240, $maxLabelPx + 24));
    $totalWidth = $labelWidth + count($dateColumns) * $dayWidth;
    $today = \Illuminate\Support\Carbon::today();
    $months = [];
    foreach ($dateColumns as $index => $col) {
        $key = $col['date']->format('Y-n');
        if (! isset($months[$key])) {
            $months[$key] = ['label' => $col['date']->format('Y年n月'), 'start' => $index, 'span' => 0];
        }
        $months[$key]['span']++;
    }
@endphp

<x-filament-panels::page>
    <div class="flex flex-wrap items-center gap-4 text-xs text-gray-600 dark:text-gray-300">
        <div class="flex items-center gap-2">
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-left" wire:click="shiftPrev">
                前へ
            </x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="resetToday">
                本日
            </x-filament::button>
            <x-filament::button color="gray" size="sm" icon="heroicon-o-chevron-right" icon-position="after" wire:click="shiftNext">
                次へ
            </x-filament::button>
            <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-path" wire:click="sync">
                同期
            </x-filament::button>
        </div>

        <span class="text-base font-medium text-gray-800 dark:text-gray-100">
            {{ $this->startDate()->format('Y/n/j') }} 〜 {{ $this->endDate()->format('Y/n/j') }}
            <span class="text-xs font-normal text-gray-500 dark:text-gray-400">（{{ $this->days }}日間）</span>
        </span>

        <label class="ml-auto inline-flex cursor-pointer items-center gap-1.5 text-xs text-gray-700 dark:text-gray-200">
            <input type="checkbox" wire:click="toggleCancelled" @checked($this->showCancelled)
                   class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500 dark:border-white/20 dark:bg-white/5" />
            <span>キャンセルを表示</span>
        </label>

        <div class="flex flex-wrap items-center gap-3">
            <span class="font-medium">凡例:</span>
            @foreach ($this->channelLegend as $item)
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block h-3 w-3 rounded-sm {{ $item['bg'] }}"></span>
                    <span>{{ $item['label'] }}</span>
                </span>
            @endforeach
        </div>
    </div>

    <div class="relative overflow-auto rounded-lg border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900"
         style="max-height: 75vh;">
        <div style="width: {{ $totalWidth }}px;">
            {{-- 月ヘッダ（月名はスクロールしても常に見えるよう sticky） --}}
            <div class="sticky top-0 z-30 flex border-b border-gray-200 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-gray-900/95">
                <div class="sticky left-0 z-40 flex items-center justify-center border-r border-gray-200 bg-white/95 text-xs font-medium text-gray-500 dark:border-white/10 dark:bg-gray-900/95 dark:text-gray-400"
                     style="width: {{ $labelWidth }}px; height: 28px;">
                    リスティング
                </div>
                @foreach ($months as $month)
                    <div class="border-r border-gray-200 dark:border-white/10"
                         style="width: {{ $month['span'] * $dayWidth }}px; height: 28px;">
                        <span class="sticky inline-block px-3 text-xs font-semibold leading-7 text-gray-700 dark:text-gray-200"
                              style="left: {{ $labelWidth + 4 }}px;">
                            {{ $month['label'] }}
                        </span>
                    </div>
                @endforeach
            </div>

            {{-- 日付ヘッダ --}}
            <div class="sticky z-30 flex border-b border-gray-200 bg-white/95 backdrop-blur dark:border-white/10 dark:bg-gray-900/95"
                 style="top: 28px;">
                <div class="sticky left-0 z-40 border-r border-gray-200 bg-white/95 dark:border-white/10 dark:bg-gray-900/95"
                     style="width: {{ $labelWidth }}px; height: 48px;"></div>
                @foreach ($dateColumns as $col)
                    @php
                        $isSunday = $col['date']->dayOfWeek === 0;
                        $isSaturday = $col['date']->dayOfWeek === 6;
                        $textColor = $col['isToday']
                            ? 'text-primary-700 dark:text-primary-300'
                            : (($isSunday || $col['isHoliday']) ? 'text-rose-600 dark:text-rose-400'
                                : ($isSaturday ? 'text-blue-600 dark:text-blue-400' : 'text-gray-600 dark:text-gray-300'));
                    @endphp
                    <div class="flex flex-col items-center justify-center border-r border-gray-200 text-xs dark:border-white/10 {{ $textColor }} {{ $col['isToday'] ? 'bg-primary-50 dark:bg-primary-950/40 font-semibold' : '' }}"
                         style="width: {{ $dayWidth }}px; height: 48px;"
                         @if ($col['isHoliday']) title="{{ $col['holidayName'] }}" @endif>
                        <span>
                            {{ $col['date']->isoFormat('ddd') }}@if ($col['isHoliday'])(祝)@endif
                        </span>
                        <span class="text-sm">{{ $col['date']->format('j') }}</span>
                    </div>
                @endforeach
            </div>

            {{-- リスティング行 --}}
            @foreach ($listings as $listing)
                @php
                    $cancelledLanes = $this->cancelledLanesFor($listing);
                    $laneCount = count($cancelledLanes);
                    $rowHeight = $activeAreaHeight + ($laneCount > 0 ? $cancelledLaneGap + $laneCount * $cancelledLanePitch : 0);
                @endphp
                <div class="flex border-b border-gray-200 dark:border-white/10" style="height: {{ $rowHeight }}px;">
                    {{-- 左固定: リスティング名 --}}
                    <div class="sticky left-0 z-20 flex flex-col justify-center border-r border-gray-200 bg-white px-3 dark:border-white/10 dark:bg-gray-900"
                         style="width: {{ $labelWidth }}px;">
                        <div class="truncate text-sm font-medium text-gray-800 dark:text-gray-100">
                            {{ $listing->display_name ?? $listing->name }}
                        </div>
                        @if ($listing->building)
                            <div class="truncate text-xs text-gray-500 dark:text-gray-400">
                                {{ $listing->building->name }}
                            </div>
                        @endif
                    </div>

                    {{-- タイムライン --}}
                    <div class="relative" style="width: {{ count($dateColumns) * $dayWidth }}px;">
                        {{-- 日付グリッド線＋過去日ハッチ --}}
                        @foreach ($dateColumns as $idx => $col)
                            @php
                                $bg = '';
                                if ($col['isPast']) {
                                    $bg = 'bg-[repeating-linear-gradient(45deg,rgba(148,163,184,0.10)_0_6px,transparent_6px_12px)]';
                                } elseif ($col['isToday']) {
                                    $bg = 'bg-primary-50/40 dark:bg-primary-950/30';
                                } elseif ($col['isWeekend'] || $col['isHoliday']) {
                                    $bg = 'bg-gray-50 dark:bg-white/5';
                                }
                            @endphp
                            <div class="absolute top-0 bottom-0 border-r border-gray-100 dark:border-white/5 {{ $bg }}"
                                 style="left: {{ $idx * $dayWidth }}px; width: {{ $dayWidth }}px;"></div>
                        @endforeach

                        {{-- 有効予約とブロックは上段の固定高エリアに描画（行が伸びても伸縮しない） --}}
                        <div class="absolute inset-x-0 top-0" style="height: {{ $activeAreaHeight }}px;">
                        {{-- ブロックバー（beds24 のカレンダーで availability=false に
                             されている連続日、実予約と重ならないもの） --}}
                        @foreach ($this->blocksFor($listing) as $seg)
                            @php
                                $leftShiftDays = $seg['clippedLeft'] ? 0 : 0.5;
                                $rightShiftDays = $seg['clippedRight'] ? 0 : 0.5;
                                $barStartDays = $seg['offsetDays'] + $leftShiftDays;
                                $barEndDays = $seg['offsetDays'] + $seg['spanDays'] + $rightShiftDays;
                                $leftPx = $barStartDays * $dayWidth + 2;
                                $widthPx = max(($barEndDays - $barStartDays) * $dayWidth - 4, 24);
                                $roundLeft = $seg['clippedLeft'] ? 'rounded-l-none' : 'rounded-l-full';
                                $roundRight = $seg['clippedRight'] ? 'rounded-r-none' : 'rounded-r-full';
                            @endphp
                            <div title="ブロック"
                                 class="absolute flex cursor-default items-center gap-1.5 overflow-hidden border border-gray-600 bg-gray-500 bg-[repeating-linear-gradient(45deg,rgba(255,255,255,0.18)_0_6px,transparent_6px_12px)] px-2 text-xs font-medium text-white shadow-sm {{ $roundLeft }} {{ $roundRight }}"
                                 style="top: {{ $activeBarInsetY }}px; bottom: {{ $activeBarInsetY }}px; left: {{ $leftPx }}px; width: {{ $widthPx }}px;">
                                <span class="truncate">ブロック</span>
                            </div>
                        @endforeach

                        {{-- 予約バー（キャンセル除く） --}}
                        @foreach ($this->activeBookingsFor($listing) as $seg)
                            @php
                                $booking = $seg['booking'];
                                $style = $this->bookingStyle($booking);
                                $isBlack = $booking->status === \App\Models\Booking::STATUS_BLACK;
                                // 予約バーは「到着日の正午 → 出発日の正午」で描画（Airbnb 流）。
                                // - clippedLeft のときは表示範囲の左端から
                                // - clippedRight のときは表示範囲の右端まで
                                $leftShiftDays = $seg['clippedLeft'] ? 0 : 0.5;
                                $rightShiftDays = $seg['clippedRight'] ? 0 : 0.5;
                                $barStartDays = $seg['offsetDays'] + $leftShiftDays;
                                $barEndDays = $seg['offsetDays'] + $seg['spanDays'] + $rightShiftDays;
                                $leftPx = $barStartDays * $dayWidth + 2;
                                $widthPx = max(($barEndDays - $barStartDays) * $dayWidth - 4, 24);
                                $guestName = trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''));
                                if ($isBlack) {
                                    $guestName = 'ブロック';
                                } elseif ($guestName === '') {
                                    $guestName = '(名前不明)';
                                }
                                $guestCount = $isBlack ? 0 : $this->guestCount($booking);
                                $roundLeft = $seg['clippedLeft'] ? 'rounded-l-none' : 'rounded-l-full';
                                $roundRight = $seg['clippedRight'] ? 'rounded-r-none' : 'rounded-r-full';
                                $tooltip = $isBlack
                                    ? sprintf('ブロック | %s〜%s', $booking->arrival?->format('n/j'), $booking->departure?->format('n/j'))
                                    : sprintf(
                                        '%s | %s〜%s | %s | %d名',
                                        $guestName,
                                        $booking->arrival?->format('n/j'),
                                        $booking->departure?->format('n/j'),
                                        $style['label'],
                                        $guestCount,
                                    );
                                $baseBar = 'absolute flex items-center gap-1.5 overflow-hidden border px-2 text-xs font-medium text-white shadow-sm';
                                $barInsetStyle = sprintf('top: %dpx; bottom: %dpx;', $activeBarInsetY, $activeBarInsetY);
                                $blackPattern = $isBlack
                                    ? 'bg-[repeating-linear-gradient(45deg,rgba(255,255,255,0.18)_0_6px,transparent_6px_12px)]'
                                    : '';
                            @endphp
                            @if ($isBlack)
                                <div title="{{ $tooltip }}"
                                     class="{{ $baseBar }} {{ $style['bg'] }} {{ $style['border'] }} {{ $roundLeft }} {{ $roundRight }} {{ $blackPattern }} cursor-default"
                                     style="{{ $barInsetStyle }} left: {{ $leftPx }}px; width: {{ $widthPx }}px;">
                                    <span class="truncate">{{ $guestName }}</span>
                                </div>
                            @else
                                <a href="{{ url('/conversations/'.$booking->beds24_booking_id) }}"
                                   title="{{ $tooltip }}"
                                   class="{{ $baseBar }} transition hover:brightness-110 focus:outline-none focus:ring-2 focus:ring-white/60 {{ $style['bg'] }} {{ $style['border'] }} {{ $roundLeft }} {{ $roundRight }}"
                                   style="{{ $barInsetStyle }} left: {{ $leftPx }}px; width: {{ $widthPx }}px;">
                                    <span class="truncate">{{ $guestName }}</span>
                                    @if ($guestCount > 0)
                                        <span class="ml-auto inline-flex shrink-0 items-center gap-0.5 rounded-full bg-white/25 px-1.5 py-0.5 text-[10px] leading-none">
                                            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="h-3 w-3">
                                                <path d="M10 8a3 3 0 100-6 3 3 0 000 6zM3.465 14.493a1.23 1.23 0 00.41 1.412A9.957 9.957 0 0010 18c2.31 0 4.438-.784 6.131-2.1.43-.333.604-.903.408-1.41a7.002 7.002 0 00-13.074.003z"/>
                                            </svg>
                                            {{ $guestCount }}
                                        </span>
                                    @endif
                                </a>
                            @endif
                        @endforeach
                        </div>{{-- /有効予約エリア --}}

                        {{-- キャンセル予約: 有効予約エリアの下に細帯で積み下げ表示。
                             同時に複数キャンセルが重なる日は貪欲パッキングでサブレーンを増やす。 --}}
                        @foreach ($cancelledLanes as $laneIdx => $lane)
                            @foreach ($lane as $seg)
                                @php
                                    $booking = $seg['booking'];
                                    $style = $this->bookingStyle($booking);
                                    $leftShiftDays = $seg['clippedLeft'] ? 0 : 0.5;
                                    $rightShiftDays = $seg['clippedRight'] ? 0 : 0.5;
                                    $barStartDays = $seg['offsetDays'] + $leftShiftDays;
                                    $barEndDays = $seg['offsetDays'] + $seg['spanDays'] + $rightShiftDays;
                                    $leftPx = $barStartDays * $dayWidth + 2;
                                    $widthPx = max(($barEndDays - $barStartDays) * $dayWidth - 4, 24);
                                    $guestName = trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''));
                                    if ($guestName === '') {
                                        $guestName = '(名前不明)';
                                    }
                                    $roundLeft = $seg['clippedLeft'] ? 'rounded-l-none' : 'rounded-l-full';
                                    $roundRight = $seg['clippedRight'] ? 'rounded-r-none' : 'rounded-r-full';
                                    $topPx = $activeAreaHeight + $cancelledLaneGap + $laneIdx * $cancelledLanePitch;
                                    $tooltip = sprintf(
                                        '[キャンセル] %s | %s〜%s | %s',
                                        $guestName,
                                        $booking->arrival?->format('n/j'),
                                        $booking->departure?->format('n/j'),
                                        $style['label'],
                                    );
                                @endphp
                                <a href="{{ url('/conversations/'.$booking->beds24_booking_id) }}"
                                   title="{{ $tooltip }}"
                                   class="absolute flex items-center overflow-hidden border border-dashed px-1.5 text-[10px] leading-none text-white opacity-60 line-through transition hover:opacity-90 focus:outline-none focus:ring-1 focus:ring-white/60 {{ $style['bg'] }} {{ $style['border'] }} {{ $roundLeft }} {{ $roundRight }}"
                                   style="top: {{ $topPx }}px; height: {{ $cancelledLaneHeight }}px; left: {{ $leftPx }}px; width: {{ $widthPx }}px;">
                                    <span class="truncate">{{ $guestName }}</span>
                                </a>
                            @endforeach
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($listings->isEmpty())
                <div class="flex items-center justify-center py-16 text-sm text-gray-500 dark:text-gray-400">
                    表示できるリスティングがありません。
                </div>
            @endif
        </div>
    </div>
</x-filament-panels::page>
