<?php

namespace App\Filament\Resources\GuestChannels\Tables;

use App\Filament\Pages\GuestChannels\ShowGuestChannel;
use App\Models\GuestChannel;
use App\Models\GuestChannelMessage;
use App\Models\Listing;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * ゲストチャット一覧。beds24 messages 側 ListConversations と揃えて
 * 状態バッジ / 日時 / ゲスト+リスティング / 最新メッセージの 4 列構成。
 *
 * 状態:
 *  - 要返信: 最新が guest かつ no_reply_needed=false
 *  - 返信不要: 最新が guest かつ no_reply_needed=true
 *  - 返信済: 最新が host
 *  - システム: 最新が system (通知のみ)
 */
class GuestChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // 予約確定後 (booking_id あり) は「メッセージ」一覧に移るためここでは除外。
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('booking_id')->with(['messages']))
            ->defaultSort('updated_at', 'desc')
            ->recordUrl(fn (GuestChannel $r): string => ShowGuestChannel::getUrl(['channelId' => $r->id]))
            ->columns([
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->getStateUsing(fn (GuestChannel $r): string => self::statusOf($r))
                    ->color(fn (string $state): string => match ($state) {
                        'unreplied' => 'danger',
                        'no_reply_needed' => 'gray',
                        'replied' => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'unreplied' => '要返信',
                        'no_reply_needed' => '返信不要',
                        'replied' => '返信済',
                        'system' => 'システム',
                        default => '-',
                    }),
                TextColumn::make('latest_sent_at')
                    ->label('日時')
                    ->getStateUsing(fn (GuestChannel $r) => self::latestMessage($r)?->sent_at)
                    ->dateTime('n月j日 H:i')
                    ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('updated_at', $direction)),
                TextColumn::make('guest_and_listing')
                    ->label('ゲスト / リスティング')
                    ->html()
                    ->getStateUsing(function (GuestChannel $r): string {
                        $guestName = $r->guest_name !== null && $r->guest_name !== ''
                            ? e($r->guest_name)
                            : '<span class="text-gray-400">(未入力)</span>';

                        $listingLabel = self::listingLabel($r->room_slug);
                        if ($listingLabel === null) {
                            $listingLabel = '<span class="text-gray-400">—</span>';
                        } else {
                            $listingLabel = e($listingLabel);
                        }

                        $subParts = [
                            e($r->guest_email),
                            strtoupper($r->guest_locale),
                        ];
                        if ($r->booking_id !== null) {
                            $subParts[] = '<span class="opacity-60">予約 #'.e((string) $r->booking_id).'</span>';
                        }
                        $subLine = '<div class="text-xs text-gray-500 dark:text-gray-400 mt-0.5">'.implode(' · ', $subParts).'</div>';

                        return '<div>'.$guestName.'  '.$listingLabel.'</div>'.$subLine;
                    })
                    ->searchable(
                        query: fn (Builder $query, string $search): Builder => $query->where(function (Builder $q) use ($search) {
                            $q->where('guest_name', 'ilike', "%{$search}%")
                                ->orWhere('guest_email', 'ilike', "%{$search}%");
                        })
                    ),
                TextColumn::make('latest_message')
                    ->label('最新メッセージ')
                    ->getStateUsing(function (GuestChannel $r): string {
                        $latest = self::latestMessage($r);
                        if ($latest === null) {
                            return '';
                        }
                        $text = $latest->japaneseTranslation() ?? $latest->message;
                        $prefix = match ($latest->source) {
                            GuestChannelMessage::SOURCE_GUEST => 'ゲスト: ',
                            GuestChannelMessage::SOURCE_HOST => 'ホスト: ',
                            GuestChannelMessage::SOURCE_SYSTEM => 'システム: ',
                            default => '',
                        };

                        return $prefix.Str::limit($text, 60);
                    })
                    ->tooltip(function (GuestChannel $r): ?string {
                        $latest = self::latestMessage($r);

                        return $latest?->japaneseTranslation() ?? $latest?->message;
                    })
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('reply_status')
                    ->label('返信ステータス')
                    ->options([
                        'unreplied' => '要返信',
                        'no_reply_needed' => '返信不要',
                        'replied' => '返信済',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        $latestIdSub = 'SELECT MAX(id) FROM guest_channel_messages WHERE guest_channel_id = guest_channels.id';

                        return match ($value) {
                            'unreplied' => $query->whereRaw(
                                "EXISTS (SELECT 1 FROM guest_channel_messages m WHERE m.guest_channel_id = guest_channels.id AND m.id = ({$latestIdSub}) AND m.source = ? AND m.no_reply_needed = false)",
                                [GuestChannelMessage::SOURCE_GUEST],
                            ),
                            'no_reply_needed' => $query->whereRaw(
                                "EXISTS (SELECT 1 FROM guest_channel_messages m WHERE m.guest_channel_id = guest_channels.id AND m.id = ({$latestIdSub}) AND m.source = ? AND m.no_reply_needed = true)",
                                [GuestChannelMessage::SOURCE_GUEST],
                            ),
                            'replied' => $query->whereRaw(
                                "EXISTS (SELECT 1 FROM guest_channel_messages m WHERE m.guest_channel_id = guest_channels.id AND m.id = ({$latestIdSub}) AND m.source = ?)",
                                [GuestChannelMessage::SOURCE_HOST],
                            ),
                            default => $query,
                        };
                    }),
                SelectFilter::make('guest_locale')
                    ->label('言語')
                    ->options([
                        'ja' => '日本語',
                        'en' => 'English',
                        'es' => 'Español',
                        'zh' => '中文',
                        'ko' => '한국어',
                    ]),
            ])
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25);
    }

    private static function statusOf(GuestChannel $channel): string
    {
        $latest = self::latestMessage($channel);
        if ($latest === null) {
            return 'system';
        }
        if ($latest->source === GuestChannelMessage::SOURCE_GUEST) {
            return $latest->no_reply_needed ? 'no_reply_needed' : 'unreplied';
        }
        if ($latest->source === GuestChannelMessage::SOURCE_HOST) {
            return 'replied';
        }

        return 'system';
    }

    private static function latestMessage(GuestChannel $channel): ?GuestChannelMessage
    {
        return $channel->messages->sortByDesc('id')->first();
    }

    private static function listingLabel(?string $roomSlug): ?string
    {
        if ($roomSlug === null) {
            return null;
        }
        $beds24RoomId = (int) config('rooms.'.$roomSlug.'.beds24_room_id', 0);
        if ($beds24RoomId !== 0) {
            $listing = Listing::query()->where('beds24_room_id', $beds24RoomId)->first();
            if ($listing !== null) {
                return $listing->name;
            }
        }

        return (string) config('rooms.'.$roomSlug.'.name', $roomSlug);
    }
}
