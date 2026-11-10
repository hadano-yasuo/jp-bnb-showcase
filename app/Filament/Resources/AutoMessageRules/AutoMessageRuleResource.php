<?php

namespace App\Filament\Resources\AutoMessageRules;

use App\Filament\Resources\AutoMessageRules\Pages\CreateAutoMessageRule;
use App\Filament\Resources\AutoMessageRules\Pages\EditAutoMessageRule;
use App\Filament\Resources\AutoMessageRules\Pages\ListAutoMessageRules;
use App\Models\AutoMessageRule;
use App\Models\Listing;
use App\Services\AutoMessage\BodyRenderer;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 自動送信メッセージのルール CRUD。
 *
 * 現段階では is_enabled=true にしても実送信されない（dry-run のみ実装）。
 * 実送信は明示指示があるまで実装しない方針（memory: feedback_execution_off_by_default）。
 */
class AutoMessageRuleResource extends Resource
{
    protected static ?string $model = AutoMessageRule::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationLabel = '自動送信ルール';

    protected static ?string $modelLabel = '自動送信ルール';

    protected static ?string $pluralModelLabel = '自動送信ルール';

    protected static ?int $navigationSort = 18;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('基本情報')
                            ->schema([
                                TextInput::make('name')
                                    ->label('ルール名')
                                    ->required()
                                    ->maxLength(128),
                                Toggle::make('is_enabled')
                                    ->label('有効化（実送信 ON）')
                                    ->helperText('現在は dry-run のみ実装。ON にしてもログ記録のみ、実送信は行われません。')
                                    ->default(false),
                                DateTimePicker::make('enabled_from')
                                    ->label('利用開始日時')
                                    ->helperText('この日時より前に予約作成された予約には送信しません。空欄にすると全予約を止めます。通常はデフォルト値のままで OK。')
                                    ->seconds(false)
                                    ->default(fn () => now())
                                    ->columnSpanFull(),
                            ])
                            ->columns(2),

                        self::targetSection(),

                        self::triggerSection(),
                    ])
                    ->extraAttributes(['class' => 'auto-message-form-left'])
                    ->columnSpan(['lg' => 4]),

                Group::make()
                    ->schema([self::bodySection(1.0)])
                    ->columnSpan(['lg' => 6]),
            ])
            ->columns(10);
    }

    private static function triggerSection(): Section
    {
        return Section::make('トリガー')
            ->description('「キャンセル時」トリガー以外はキャンセルしたゲストには送信されません。')
            ->schema([
                // 1行目: イベント × タイミング
                Select::make('trigger_event')
                    ->label('イベント')
                    ->options([
                        AutoMessageRule::EVENT_BOOKING_CREATED => '予約受付時',
                        AutoMessageRule::EVENT_CHECKIN => 'チェックイン',
                        AutoMessageRule::EVENT_CHECKOUT => 'チェックアウト',
                        AutoMessageRule::EVENT_CANCELLED => 'キャンセル時',
                    ])
                    ->required()
                    ->live(),

                Select::make('_offset_direction')
                    ->label('タイミング')
                    ->options(['before' => '前', 'after' => '後', 'same' => '当日'])
                    ->default('before')
                    ->required(fn (Get $get): bool => self::isCheckInOutEvent($get))
                    ->visible(fn (Get $get): bool => self::isCheckInOutEvent($get))
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncOffsetDays($get, $set)),

                // 2行目: 日数 × 時刻（checkin/checkout 用）
                TextInput::make('_offset_days_abs')
                    ->label('日数')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(60)
                    ->required(fn (Get $get): bool => self::isCheckInOutEvent($get) && $get('_offset_direction') !== 'same')
                    ->visible(fn (Get $get): bool => self::isCheckInOutEvent($get) && $get('_offset_direction') !== 'same')
                    ->dehydrated(false)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::syncOffsetDays($get, $set)),

                TimePicker::make('time_of_day')
                    ->label('時刻（Asia/Tokyo）')
                    ->seconds(false)
                    ->displayFormat('H:i')
                    ->format('H:i')
                    ->required(fn (Get $get): bool => self::isCheckInOutEvent($get))
                    ->visible(fn (Get $get): bool => self::isCheckInOutEvent($get)),

                // booking_created / cancelled 用（1行目右側に入る）
                TextInput::make('offset_minutes')
                    ->label('オフセット（分）')
                    ->helperText('例: 0=即時, 60=1時間後, 1440=1日後')
                    ->numeric()
                    ->required(fn (Get $get): bool => self::isMinutesBasedEvent($get))
                    ->visible(fn (Get $get): bool => self::isMinutesBasedEvent($get)),

                Hidden::make('offset_days'),

                Toggle::make('only_when_staying')
                    ->label('滞在中のゲストにのみ送信')
                    ->helperText('ON にすると、チェックアウト日を過ぎたスケジュール（例: 1 泊予約に対する「チェックイン+2 日」）は送信されません。')
                    ->default(false)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    private static function targetSection(): Section
    {
        return Section::make('対象')
            ->schema([
                Select::make('channel_scope')
                    ->label('予約チャネル')
                    ->options([
                        AutoMessageRule::CHANNEL_ALL => 'すべて',
                        AutoMessageRule::CHANNEL_AIRBNB => 'Airbnb',
                        AutoMessageRule::CHANNEL_BOOKING => 'Booking.com',
                        AutoMessageRule::CHANNEL_DIRECT => '直接予約',
                    ])
                    ->required()
                    ->selectablePlaceholder(false)
                    ->default(AutoMessageRule::CHANNEL_ALL),

                Select::make('locale')
                    ->label('言語（未設定=自動）')
                    ->options(['ja' => '日本語', 'en' => '英語'])
                    ->nullable(),

                Select::make('condition')
                    ->label('発火条件（任意）')
                    ->helperText('選択すると、条件を満たす予約のみ発火します。空欄=無条件で発火。同じ条件のルールは一覧のフィルタでまとめて有効化できます。')
                    ->options(AutoMessageRule::conditionLabels())
                    ->nullable(),

                Select::make('listings')
                    ->label('対象リスティング')
                    ->relationship(
                        name: 'listings',
                        titleAttribute: 'display_name',
                        modifyQueryUsing: fn ($query) => $query->orderBy('display_name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Listing $r): string => $r->display_name.($r->building !== null ? '（'.$r->building->name.'）' : '')
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function bodySection(float $heightMultiplier = 1.5): Section
    {
        $heightExpr = "((l.getBoundingClientRect().height - 100) * {$heightMultiplier}) + 'px'";

        return Section::make('本文')
            ->description('変数を選ぶとカーソル位置に挿入されます。送信時に予約情報で置換されます。')
            ->schema([
                Select::make('_insert_variable')
                    ->label('変数を挿入')
                    ->options(BodyRenderer::availableVariables())
                    ->searchable()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateUpdated(function (?string $state, $livewire, Set $set): void {
                        if ($state === null || $state === '') {
                            return;
                        }
                        $payload = json_encode(['variable' => $state], JSON_UNESCAPED_UNICODE);
                        $livewire->js("window.dispatchEvent(new CustomEvent('insert-variable-at-cursor', {detail: {$payload}}))");
                        $set('_insert_variable', null);
                    })
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->label('本文')
                    ->required()
                    ->rows(20)
                    ->extraInputAttributes([
                        'x-init' => <<<JS
                            (() => {
                                // CSS を一度だけ注入。!important で Filament の inline style を打ち消す
                                if (!document.getElementById('body-textarea-height-css')) {
                                    const s = document.createElement('style');
                                    s.id = 'body-textarea-height-css';
                                    s.textContent = '[data-body-textarea-wrapper]{height:var(--body-textarea-height,auto)!important}[data-body-textarea-wrapper] textarea{height:100%!important;box-sizing:border-box}';
                                    document.head.appendChild(s);
                                }
                                if (\$el.parentElement) \$el.parentElement.setAttribute('data-body-textarea-wrapper', '');
                                const sync = () => {
                                    const l = document.querySelector('.auto-message-form-left');
                                    if (!l) return;
                                    if (window.innerWidth < 1024) {
                                        document.documentElement.style.removeProperty('--body-textarea-height');
                                        return;
                                    }
                                    const target = {$heightExpr};
                                    document.documentElement.style.setProperty('--body-textarea-height', target);
                                };
                                \$nextTick(sync);
                                window.addEventListener('resize', sync);
                                const l = document.querySelector('.auto-message-form-left');
                                if (l && window.ResizeObserver) {
                                    new ResizeObserver(sync).observe(l);
                                }
                            })()
                        JS,
                        'x-on:insert-variable-at-cursor.window' => <<<'JS'
                            const s = $el.selectionStart ?? (state ?? '').length;
                            const e = $el.selectionEnd ?? s;
                            const v = state ?? '';
                            const ins = $event.detail.variable;
                            state = v.slice(0, s) + ins + v.slice(e);
                            $nextTick(() => {
                                $el.focus();
                                $el.selectionStart = $el.selectionEnd = s + ins.length;
                            });
                        JS,
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->modifyQueryUsing(fn (Builder $query) => $query->with('listings:id,display_name'))
            ->columns([
                TextColumn::make('name')->label('ルール名')->searchable()->sortable(),
                TextColumn::make('condition')
                    ->label('発火条件')
                    ->badge()
                    ->placeholder('無条件')
                    ->formatStateUsing(fn (?string $state): string => AutoMessageRule::conditionLabels()[$state] ?? '無条件'),
                TextColumn::make('trigger_event')
                    ->label('イベント')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        AutoMessageRule::EVENT_BOOKING_CREATED => '予約受付時',
                        AutoMessageRule::EVENT_CHECKIN => 'チェックイン',
                        AutoMessageRule::EVENT_CHECKOUT => 'チェックアウト',
                        AutoMessageRule::EVENT_CANCELLED => 'キャンセル時',
                        default => $state,
                    }),
                TextColumn::make('offset_display')
                    ->label('オフセット')
                    ->state(fn (AutoMessageRule $r): string => match ($r->trigger_event) {
                        AutoMessageRule::EVENT_BOOKING_CREATED,
                        AutoMessageRule::EVENT_CANCELLED => sprintf('%d分後', (int) $r->offset_minutes),
                        AutoMessageRule::EVENT_CHECKIN, AutoMessageRule::EVENT_CHECKOUT => self::formatCheckOffset(
                            (int) $r->offset_days,
                            (string) ($r->time_of_day ?? ''),
                        ),
                        default => '',
                    }),
                TextColumn::make('channel_scope')->label('チャネル')->badge(),
                TextColumn::make('listings.display_name')
                    ->label('リスティング')
                    ->badge()
                    ->wrap()
                    ->placeholder('未指定'),
                IconColumn::make('is_enabled')->label('有効')->boolean(),
            ])
            ->filters([
                SelectFilter::make('condition')
                    ->label('発火条件')
                    ->options(AutoMessageRule::conditionLabels()),
                SelectFilter::make('trigger_event')
                    ->label('イベント')
                    ->options([
                        AutoMessageRule::EVENT_BOOKING_CREATED => '予約受付時',
                        AutoMessageRule::EVENT_CHECKIN => 'チェックイン',
                        AutoMessageRule::EVENT_CHECKOUT => 'チェックアウト',
                        AutoMessageRule::EVENT_CANCELLED => 'キャンセル時',
                    ]),
                SelectFilter::make('listing')
                    ->label('リスティング')
                    ->options(function (): array {
                        $options = [];
                        foreach (Listing::query()->orderBy('display_name')->get() as $l) {
                            $options[(string) $l->id] = $l->display_name.($l->building !== null ? '（'.$l->building->name.'）' : '');
                        }

                        return $options;
                    })
                    ->query(function (Builder $query, array $data): Builder {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return $query;
                        }

                        return $query->whereHas('listings', fn ($q) => $q->where('listings.id', (int) $value));
                    }),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('enable')
                        ->label('選択したルールを有効化')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_enabled' => true]))
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('disable')
                        ->label('選択したルールを無効化')
                        ->icon('heroicon-o-x-circle')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(fn ($records) => $records->each->update(['is_enabled' => false]))
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListAutoMessageRules::route('/'),
            'create' => CreateAutoMessageRule::route('/create'),
            'edit' => EditAutoMessageRule::route('/{record}/edit'),
        ];
    }

    private static function isCheckInOutEvent(Get $get): bool
    {
        return in_array($get('trigger_event'), [
            AutoMessageRule::EVENT_CHECKIN,
            AutoMessageRule::EVENT_CHECKOUT,
        ], true);
    }

    private static function isMinutesBasedEvent(Get $get): bool
    {
        return in_array($get('trigger_event'), [
            AutoMessageRule::EVENT_BOOKING_CREATED,
            AutoMessageRule::EVENT_CANCELLED,
        ], true);
    }

    /**
     * _offset_direction と _offset_days_abs から offset_days を合成する。
     * before → 負, after → 正, same → 0。
     */
    private static function syncOffsetDays(Get $get, Set $set): void
    {
        $direction = (string) $get('_offset_direction');
        if ($direction === 'same') {
            $set('offset_days', 0);

            return;
        }

        $abs = (int) $get('_offset_days_abs');
        if ($abs <= 0) {
            $set('offset_days', null);

            return;
        }

        $set('offset_days', $direction === 'before' ? -$abs : $abs);
    }

    private static function formatCheckOffset(int $days, string $timeOfDay): string
    {
        $direction = match (true) {
            $days < 0 => sprintf('%d日前', abs($days)),
            $days > 0 => sprintf('%d日後', $days),
            default => '当日',
        };

        return trim($direction.' '.$timeOfDay);
    }
}
