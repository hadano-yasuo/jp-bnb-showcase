<?php

namespace App\Filament\Pages\Conversations;

use App\Jobs\Beds24\SyncMessagesJob;
use App\Models\Booking;
use App\Models\Building;
use App\Models\Listing;
use App\Models\Message;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * 予約単位の会話一覧。/admin/conversations。
 *
 * Filament Table を使い、latest-per-booking のメッセージを1行ずつ表示。
 * 建物・リスティング・キャンセル・ステータスで絞り込み可能。
 *
 * 未返信 = 最新メッセージが guest かつ no_reply_needed=false
 * 返信不要 = 最新メッセージが guest かつ no_reply_needed=true
 * 返信済 = 最新メッセージが host
 * system = 最新メッセージが system（通知のみ、返信対象外）
 */
class ListConversations extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.conversations.list';

    protected static ?string $slug = 'conversations';

    protected static ?string $navigationLabel = 'メッセージ';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 15;

    /**
     * 左メニュー右側のバッジ。未返信予約数。
     *
     * 「予約ごとの最新メッセージ」は sent_at DESC NULLS LAST, id DESC で判定する。
     * beds24_message_id は自社サイト直接予約の Message で NULL になり、ローカル id は
     * 初回一括 sync が id DESC で挿入される都合で beds24 発行時刻と逆転するため、
     * どちらも単独では「最新」の指標として使えない。
     */
    public static function getNavigationBadge(): ?string
    {
        $count = Message::query()
            ->whereIn('id', self::latestMessageIdsPerBooking())
            ->where('source', Message::SOURCE_GUEST)
            ->where('no_reply_needed', false)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    /**
     * 予約ごとの「最新 Message」の PK を返す。
     *
     * @return Collection<int, int>
     */
    private static function latestMessageIdsPerBooking(): Collection
    {
        return Message::query()
            ->selectRaw('DISTINCT ON (beds24_booking_id) id')
            ->orderBy('beds24_booking_id')
            ->orderByRaw('sent_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->pluck('id');
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public function getTitle(): string
    {
        return 'メッセージ';
    }

    public function getHeading(): string
    {
        return 'メッセージ';
    }

    /**
     * @return array<int|string, string>
     */
    public function getBreadcrumbs(): array
    {
        return [
            'メッセージ',
        ];
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('更新')
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function () {
                    try {
                        dispatch_sync(new SyncMessagesJob);
                        Notification::make()->title('最新メッセージを取得しました')->success()->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('同期に失敗しました')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // 予約ごとの最新 Message を 1 件だけ取得。sent_at ベースで選別する理由は
            // getNavigationBadge のコメント参照。
            ->query(fn (): Builder => Message::query()->whereIn('id', self::latestMessageIdsPerBooking()))
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->defaultSort('sent_at', 'desc')
            ->recordUrl(fn (Message $record): string => url('/conversations/'.$record->beds24_booking_id))
            ->columns([
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->getStateUsing(function (Message $record): string {
                        $booking = $this->cachedBookings()->get($record->beds24_booking_id);
                        if ($booking !== null && $booking->status === Booking::STATUS_CANCELLED) {
                            return 'cancelled';
                        }

                        if ($record->source === Message::SOURCE_GUEST) {
                            return $record->no_reply_needed ? 'no_reply_needed' : 'unreplied';
                        }
                        if ($record->source === Message::SOURCE_HOST) {
                            return 'replied';
                        }

                        return 'other';
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'unreplied' => 'danger',
                        'no_reply_needed' => 'gray',
                        'replied' => 'success',
                        'cancelled' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unreplied' => '未返信',
                        'no_reply_needed' => '返信不要',
                        'replied' => '返信済',
                        'cancelled' => 'キャンセル',
                        default => '-',
                    }),
                TextColumn::make('sent_at')
                    ->label('日時')
                    ->dateTime('n月j日 H:i')
                    ->sortable(),
                TextColumn::make('guest_and_listing')
                    ->label('ゲスト / リスティング')
                    ->html()
                    ->getStateUsing(function (Message $record): string {
                        $booking = $this->cachedBookings()->get($record->beds24_booking_id);
                        $listing = $this->cachedListings()->get($record->beds24_room_id);

                        $guestName = $booking !== null
                            ? trim(($booking->first_name ?? '').' '.($booking->last_name ?? ''))
                            : '';
                        if ($guestName === '') {
                            $guestName = '<span class="text-gray-400">(名前不明)</span>';
                        } else {
                            $guestName = e($guestName);
                        }

                        $listingLabel = $listing !== null
                            ? e($listing->display_name)
                            : "(listing {$record->beds24_room_id})";

                        // 2行目: チェックイン日-チェックアウト日 · OTA · 予約ID (小さく)
                        $subParts = [];
                        if ($booking !== null) {
                            if ($booking->arrival !== null && $booking->departure !== null) {
                                $subParts[] = $booking->arrival->format('n/j').'-'.$booking->departure->format('n/j');
                            }
                            if (! empty($booking->channel)) {
                                $subParts[] = e(ucfirst($booking->channel));
                            }
                        }
                        $subParts[] = '<span class="opacity-60">#'.e((string) $record->beds24_booking_id).'</span>';
                        $subLine = '<div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">'.implode(' · ', $subParts).'</div>';

                        return '<div>'.$guestName.'  '.$listingLabel.'</div>'.$subLine;
                    })
                    ->searchable(
                        query: fn (Builder $query, string $search): Builder => $query->whereIn(
                            'beds24_booking_id',
                            Booking::query()
                                ->where(function (Builder $q) use ($search) {
                                    $q->where('first_name', 'ilike', "%{$search}%")
                                        ->orWhere('last_name', 'ilike', "%{$search}%");
                                })
                                ->pluck('beds24_booking_id')
                        )
                    ),
                TextColumn::make('message')
                    ->label('最新メッセージ')
                    ->getStateUsing(function (Message $record): string {
                        $text = $record->japaneseTranslation() ?? $record->message;
                        $prefix = match ($record->source) {
                            Message::SOURCE_GUEST => 'ゲスト: ',
                            Message::SOURCE_HOST => 'ホスト: ',
                            Message::SOURCE_SYSTEM => 'システム: ',
                            default => '',
                        };

                        return $prefix.Str::limit($text, 60);
                    })
                    ->tooltip(fn (Message $record): string => $record->japaneseTranslation() ?? $record->message)
                    ->wrap()
                    ->searchable(
                        query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search) {
                            $q->where('message', 'ilike', "%{$search}%")
                                ->orWhere('message_ja', 'ilike', "%{$search}%");
                        })
                    ),
            ])
            ->filters([
                SelectFilter::make('reply_status')
                    ->label('返信ステータス')
                    ->options([
                        'unreplied' => '未返信',
                        'no_reply_needed' => '返信不要',
                        'replied' => '返信済',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'unreplied' => $query->where('source', Message::SOURCE_GUEST)->where('no_reply_needed', false),
                        'no_reply_needed' => $query->where('source', Message::SOURCE_GUEST)->where('no_reply_needed', true),
                        'replied' => $query->where('source', Message::SOURCE_HOST),
                        default => $query,
                    }),
                SelectFilter::make('building')
                    ->label('建物')
                    ->options(fn () => Building::query()->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }
                        $roomIds = Listing::query()
                            ->where('building_id', $data['value'])
                            ->pluck('beds24_room_id')
                            ->all();

                        return $query->whereIn('beds24_room_id', $roomIds);
                    }),
                SelectFilter::make('listing')
                    ->label('リスティング')
                    ->options(fn () => Listing::query()->pluck('display_name', 'beds24_room_id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        return $query->where('beds24_room_id', $data['value']);
                    }),
                TernaryFilter::make('cancelled')
                    ->label('キャンセル')
                    ->placeholder('すべて')
                    ->trueLabel('キャンセルのみ')
                    ->falseLabel('キャンセル以外')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereIn(
                            'beds24_booking_id',
                            Booking::query()->where('status', Booking::STATUS_CANCELLED)->pluck('beds24_booking_id')
                        ),
                        false: fn (Builder $query): Builder => $query->whereNotIn(
                            'beds24_booking_id',
                            Booking::query()->where('status', Booking::STATUS_CANCELLED)->pluck('beds24_booking_id')
                        ),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    /**
     * @var Collection<int, Listing>|null
     */
    private ?Collection $listingsCache = null;

    /**
     * @var Collection<int, Booking>|null
     */
    private ?Collection $bookingsCache = null;

    /**
     * @return Collection<int, Listing>
     */
    private function cachedListings(): Collection
    {
        if ($this->listingsCache === null) {
            $this->listingsCache = Listing::query()
                ->with('building')
                ->get()
                ->keyBy('beds24_room_id');
        }

        return $this->listingsCache;
    }

    /**
     * @return Collection<int, Booking>
     */
    private function cachedBookings(): Collection
    {
        if ($this->bookingsCache === null) {
            $this->bookingsCache = Booking::query()
                ->select(['id', 'beds24_booking_id', 'first_name', 'last_name', 'status', 'arrival', 'departure', 'channel'])
                ->get()
                ->keyBy('beds24_booking_id');
        }

        return $this->bookingsCache;
    }
}
