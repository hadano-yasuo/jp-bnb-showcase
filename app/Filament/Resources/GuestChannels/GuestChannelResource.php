<?php

namespace App\Filament\Resources\GuestChannels;

use App\Filament\Resources\GuestChannels\Pages\ListGuestChannels;
use App\Filament\Resources\GuestChannels\Tables\GuestChannelsTable;
use App\Models\GuestChannel;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class GuestChannelResource extends Resource
{
    protected static ?string $model = GuestChannel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftEllipsis;

    protected static ?string $navigationLabel = 'ゲストチャット';

    protected static ?string $modelLabel = 'ゲストチャット';

    protected static ?string $pluralModelLabel = 'ゲストチャット一覧';

    protected static ?int $navigationSort = 16;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return GuestChannelsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGuestChannels::route('/'),
        ];
    }

    /**
     * 左メニュー右側のバッジ。要返信のゲストチャネル数。
     * 最新メッセージが guest かつ no_reply_needed=false の行だけカウント。
     * beds24 messages 側 ListConversations::getNavigationBadge() と同じ仕様。
     */
    public static function getNavigationBadge(): ?string
    {
        // 予約確定後の GuestChannel は「メッセージ」側で扱うので除外。
        $count = GuestChannel::query()
            ->whereNull('booking_id')
            ->whereHas('messages', function ($q) {
                $q->whereRaw('source = ? AND no_reply_needed = false AND id = (
                    SELECT MAX(id) FROM guest_channel_messages
                    WHERE guest_channel_id = guest_channels.id
                )', ['guest']);
            })
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }
}
