<?php

namespace App\Filament\Resources\AutoMessageSends;

use App\Filament\Resources\AutoMessageSends\Pages\ListAutoMessageSends;
use App\Models\AutoMessageRule;
use App\Models\AutoMessageSend;
use BackedEnum;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 自動送信の実行履歴（dry-run / sent / skipped / failed）。
 * 閲覧専用。作成・編集・削除は不可（履歴は改変させない）。
 */
class AutoMessageSendResource extends Resource
{
    protected static ?string $model = AutoMessageSend::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationLabel = '自動送信履歴';

    protected static ?string $modelLabel = '自動送信履歴';

    protected static ?string $pluralModelLabel = '自動送信履歴';

    protected static ?int $navigationSort = 19;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['rule', 'booking.listing'])
            )
            ->defaultSort('scheduled_at', 'desc')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->columns([
                TextColumn::make('scheduled_at')
                    ->label('発火時刻')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('状態')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AutoMessageSend::STATUS_PENDING => '予定',
                        AutoMessageSend::STATUS_DRY_RUN => 'dry-run',
                        AutoMessageSend::STATUS_SENT => '送信済',
                        AutoMessageSend::STATUS_SKIPPED => 'スキップ',
                        AutoMessageSend::STATUS_FAILED => '失敗',
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        AutoMessageSend::STATUS_PENDING => 'gray',
                        AutoMessageSend::STATUS_DRY_RUN => 'warning',
                        AutoMessageSend::STATUS_SENT => 'success',
                        AutoMessageSend::STATUS_SKIPPED => 'gray',
                        AutoMessageSend::STATUS_FAILED => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('rule.name')
                    ->label('ルール')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('rule.trigger_event')
                    ->label('トリガー')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        AutoMessageRule::EVENT_BOOKING_CREATED => '予約受付時',
                        AutoMessageRule::EVENT_CHECKIN => 'チェックイン',
                        AutoMessageRule::EVENT_CHECKOUT => 'チェックアウト',
                        AutoMessageRule::EVENT_CANCELLED => 'キャンセル時',
                        default => (string) $state,
                    }),
                TextColumn::make('booking.beds24_booking_id')
                    ->label('予約ID')
                    ->searchable(),
                TextColumn::make('booking.listing.display_name')
                    ->label('リスティング')
                    ->badge(),
                TextColumn::make('skip_reason')
                    ->label('スキップ理由')
                    ->badge()
                    ->color(fn (?string $state): string => match ($state) {
                        AutoMessageSend::SKIP_EMPTY_GUEST_NAME => 'warning',
                        default => 'gray',
                    })
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        AutoMessageSend::SKIP_CANCELLED => 'キャンセル済',
                        AutoMessageSend::SKIP_AFTER_DEPARTURE => 'departure過ぎ',
                        AutoMessageSend::SKIP_CHANNEL_MISMATCH => 'チャネル不一致',
                        AutoMessageSend::SKIP_OPTED_OUT => '予約単位OFF',
                        AutoMessageSend::SKIP_BEFORE_ENABLED_FROM => '有効化前',
                        AutoMessageSend::SKIP_EMPTY_GUEST_NAME => '宛名未登録',
                        'sending_not_implemented' => '実送信未実装',
                        null => '',
                        default => (string) $state,
                    }),
                TextColumn::make('rendered_body')
                    ->label('本文（展開後・日本語）')
                    ->limit(60)
                    ->tooltip(fn (AutoMessageSend $r): ?string => $r->rendered_body)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('translated_to_lang')
                    ->label('翻訳先')
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                TextColumn::make('rendered_body_translated')
                    ->label('本文（翻訳後）')
                    ->limit(60)
                    ->tooltip(fn (AutoMessageSend $r): ?string => $r->rendered_body_translated)
                    ->wrap()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('状態')
                    ->options([
                        AutoMessageSend::STATUS_PENDING => '予定',
                        AutoMessageSend::STATUS_DRY_RUN => 'dry-run',
                        AutoMessageSend::STATUS_SENT => '送信済',
                        AutoMessageSend::STATUS_SKIPPED => 'スキップ',
                        AutoMessageSend::STATUS_FAILED => '失敗',
                    ]),
                SelectFilter::make('auto_message_rule_id')
                    ->label('ルール')
                    ->relationship('rule', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('skip_reason')
                    ->label('スキップ理由')
                    ->options([
                        AutoMessageSend::SKIP_EMPTY_GUEST_NAME => '宛名未登録',
                        AutoMessageSend::SKIP_CANCELLED => 'キャンセル済',
                        AutoMessageSend::SKIP_AFTER_DEPARTURE => 'departure過ぎ',
                        AutoMessageSend::SKIP_CHANNEL_MISMATCH => 'チャネル不一致',
                        AutoMessageSend::SKIP_OPTED_OUT => '予約単位OFF',
                        AutoMessageSend::SKIP_BEFORE_ENABLED_FROM => '有効化前',
                        'sending_not_implemented' => '実送信未実装',
                    ]),
            ]);
    }

    /**
     * サイドバーの navigation に警告バッジを出す。
     * 「宛名未登録」により送信を止めた件数 = 手動対処待ち。
     */
    public static function getNavigationBadge(): ?string
    {
        $count = AutoMessageSend::query()
            ->where('status', AutoMessageSend::STATUS_SKIPPED)
            ->where('skip_reason', AutoMessageSend::SKIP_EMPTY_GUEST_NAME)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAutoMessageSends::route('/'),
        ];
    }
}
