<?php

namespace App\Filament\Resources\GuestRegistrationTokens;

use App\Filament\Resources\GuestRegistrationTokens\Pages\ListGuestRegistrationTokens;
use App\Filament\Resources\GuestRegistrationTokens\Pages\ViewGuestRegistrationToken;
use App\Models\Building;
use App\Models\GuestRegistrationToken;
use App\Models\Listing;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Js;
use Livewire\Component as LivewireComponent;

/**
 * 予約ごとのゲスト情報登録用トークンとその提出状況を管理する Resource。
 *
 * - 提出URLの複製、有効期限の手動延長、提出済みデータの閲覧が主目的。
 * - 予約ではなく Token を主軸に置く: トークン発行済み=フォームが露出している予約
 *   という業務単位が管理者の関心に一致するため。
 * - デフォルトで「本日を含む 7 日間と滞在期間がオーバーラップする予約」だけを表示。
 */
class GuestRegistrationTokenResource extends Resource
{
    protected static ?string $model = GuestRegistrationToken::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?string $navigationLabel = 'ゲスト登録';

    protected static ?string $modelLabel = 'ゲスト登録';

    protected static ?string $pluralModelLabel = 'ゲスト登録';

    protected static ?int $navigationSort = 11;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::ROLE_ADMIN);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['booking.listing.building', 'booking.guestRegistrations', 'manuallyCompletedBy'])
                ->whereHas('booking', fn (Builder $q) => $q->whereNotIn('status', ['black', 'cancelled']))
            )
            ->defaultSort('booking.arrival', 'asc')
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->columns([
                TextColumn::make('stay_status')
                    ->label('状態')
                    ->badge()
                    ->getStateUsing(fn (GuestRegistrationToken $r) => self::resolveStayStatus($r))
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, '本日 IN') => 'info',
                        str_starts_with($state, '本日 OUT') => 'warning',
                        str_starts_with($state, '宿泊中') => 'success',
                        str_ends_with($state, 'IN') => 'gray',
                        str_ends_with($state, 'OUT') => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('booking.arrival')
                    ->label('チェックイン')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('booking.departure')
                    ->label('チェックアウト')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('booking.listing.name')
                    ->label('物件')
                    ->limit(30)
                    ->toggleable(),
                TextColumn::make('booking.first_name')
                    ->label('予約者')
                    ->formatStateUsing(fn ($state, GuestRegistrationToken $r) => trim(($r->booking->first_name ?? '').' '.($r->booking->last_name ?? '')))
                    ->searchable(['first_name', 'last_name']),
                TextColumn::make('booking.api_source')
                    ->label('チャネル')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'Airbnb' => '🏠 Airbnb',
                        'Booking.com' => '🅱️ Booking',
                        'Direct' => '🏢 Direct',
                        null => '—',
                        default => (string) $state,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'Airbnb' => 'danger',
                        'Booking.com' => 'info',
                        'Direct' => 'success',
                        default => 'gray',
                    })
                    ->toggleable(),
                TextColumn::make('booking.num_adult')
                    ->label('人数')
                    ->getStateUsing(fn (GuestRegistrationToken $r): string => sprintf(
                        '%d + %d',
                        (int) ($r->booking->num_adult ?? 0),
                        (int) ($r->booking->num_child ?? 0),
                    ))
                    ->tooltip('大人 + 子供')
                    ->toggleable(),
                TextColumn::make('submitted_count')
                    ->label('登録済み')
                    ->badge()
                    ->tooltip(function (GuestRegistrationToken $r): string {
                        if ($r->isManuallyCompleted()) {
                            $who = $r->manuallyCompletedBy->name ?? '—';
                            $when = $r->manually_completed_at?->format('Y-m-d H:i') ?? '—';
                            $note = $r->manually_completed_note !== null && $r->manually_completed_note !== ''
                                ? "\nメモ: {$r->manually_completed_note}"
                                : '';

                            return "手動完了 ({$who}, {$when}){$note}";
                        }

                        return sprintf(
                            '登録済み %d 名 / 予約人数 %d 名（大人 %d + 子供 %d）',
                            $r->booking->guestRegistrations->count(),
                            (int) ($r->booking->num_adult ?? 0) + (int) ($r->booking->num_child ?? 0),
                            (int) ($r->booking->num_adult ?? 0),
                            (int) ($r->booking->num_child ?? 0),
                        );
                    })
                    ->getStateUsing(function (GuestRegistrationToken $r): string {
                        if ($r->isManuallyCompleted()) {
                            return '✓ 手動完了';
                        }

                        $submitted = $r->booking->guestRegistrations->count();
                        $expected = (int) ($r->booking->num_adult ?? 0) + (int) ($r->booking->num_child ?? 0);

                        return $expected > 0 ? "{$submitted} / {$expected}" : (string) $submitted;
                    })
                    ->color(function (GuestRegistrationToken $r): string {
                        if ($r->isManuallyCompleted()) {
                            return 'success';
                        }

                        $submitted = $r->booking->guestRegistrations->count();
                        $expected = (int) ($r->booking->num_adult ?? 0) + (int) ($r->booking->num_child ?? 0);

                        if ($expected === 0) {
                            return 'gray';
                        }

                        // 予約人数の入力ミスに気付けるよう、超過は danger で赤く目立たせる
                        if ($submitted > $expected) {
                            return 'danger';
                        }

                        if ($submitted === $expected) {
                            return 'success';
                        }

                        return 'warning';
                    }),
                TextColumn::make('image_verdict_summary')
                    ->label('画像確認')
                    ->badge()
                    ->tooltip(fn (GuestRegistrationToken $r) => self::imageVerdictTooltip($r))
                    ->getStateUsing(fn (GuestRegistrationToken $r) => self::imageVerdictSummary($r))
                    ->color(fn (string $state): string => match ($state) {
                        'invalid' => 'danger',
                        'suspicious' => 'warning',
                        'ok' => 'success',
                        'pending' => 'gray',
                        'error' => 'purple',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'invalid' => '要確認',
                        'suspicious' => '疑わしい',
                        'ok' => 'OK',
                        'pending' => '確認中',
                        'error' => 'エラー',
                        default => '—',
                    }),
                BadgeColumn::make('token_status')
                    ->label('URL')
                    ->getStateUsing(fn (GuestRegistrationToken $r) => $r->isExpired() ? 'expired' : 'active')
                    ->colors([
                        'success' => 'active',
                        'gray' => 'expired',
                    ])
                    ->formatStateUsing(fn (string $state) => $state === 'active' ? '有効' : '期限切れ')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('expires_at')
                    ->label('有効期限')
                    ->dateTime('Y-m-d')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('period')
                    ->label('期間')
                    ->columnSpanFull()
                    ->form([
                        ToggleButtons::make('preset')
                            ->label('プリセット')
                            ->inline()
                            ->options([
                                'today' => '今日',
                                '7days' => '今日から 7 日',
                                '14days' => '今日から 14 日',
                                'thismonth' => '今月',
                                'custom' => 'カスタム',
                            ])
                            ->default('7days')
                            ->live(),
                        DatePicker::make('base_date')
                            ->label('基準日')
                            ->native(false)
                            ->default(fn () => today()->toDateString())
                            ->visible(fn ($get) => $get('preset') === 'custom'),
                        TextInput::make('days')
                            ->label('日数')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(180)
                            ->default(7)
                            ->visible(fn ($get) => $get('preset') === 'custom'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        [$baseDate, $days] = self::resolvePeriod($data);
                        $end = $baseDate->copy()->addDays($days - 1);

                        return $query->whereHas('booking', function (Builder $q) use ($baseDate, $end): void {
                            $q->whereDate('arrival', '<=', $end->toDateString())
                                ->whereDate('departure', '>=', $baseDate->toDateString());
                        });
                    })
                    ->indicateUsing(function (array $data): string {
                        [$baseDate, $days] = self::resolvePeriod($data);
                        $end = $baseDate->copy()->addDays($days - 1);

                        return sprintf('%s 〜 %s (%d日間)', $baseDate->format('m/d'), $end->format('m/d'), $days);
                    }),
                SelectFilter::make('validity')
                    ->label('URL 状態')
                    ->options([
                        'active' => '有効',
                        'expired' => '期限切れ',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if ($data['value'] === 'active') {
                            return $query->where('expires_at', '>', now());
                        }

                        if ($data['value'] === 'expired') {
                            return $query->where('expires_at', '<=', now());
                        }

                        return $query;
                    }),
                SelectFilter::make('image_verdict')
                    ->label('画像確認')
                    ->options([
                        'flagged' => '要確認・疑わしい',
                        'ok' => 'OK',
                        'pending' => '確認中',
                        'none' => '画像未提出',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        if (! in_array($data['value'] ?? '', ['flagged', 'ok', 'pending', 'none'], true)) {
                            return $query;
                        }

                        return $query->whereHas('booking.guestRegistrations', function (Builder $q) use ($data): void {
                            match ($data['value']) {
                                'flagged' => $q->where(function (Builder $q2): void {
                                    // 実効 verdict は admin_verdict > id_image_verdict
                                    $q2->whereIn('admin_verdict', ['invalid'])
                                        ->orWhere(function (Builder $q3): void {
                                            $q3->whereNull('admin_verdict')
                                                ->whereIn('id_image_verdict', ['invalid', 'suspicious']);
                                        });
                                }),
                                'ok' => $q->where(function (Builder $q2): void {
                                    $q2->where('admin_verdict', 'ok')
                                        ->orWhere(function (Builder $q3): void {
                                            $q3->whereNull('admin_verdict')
                                                ->where('id_image_verdict', 'ok');
                                        });
                                }),
                                'pending' => $q->whereNull('admin_verdict')
                                    ->where('id_image_verdict', 'pending'),
                                'none' => $q->whereNull('id_image_path'),
                            };
                        });
                    }),
                SelectFilter::make('channel')
                    ->label('チャネル')
                    ->options([
                        'Airbnb' => '🏠 Airbnb',
                        'Booking.com' => '🅱️ Booking.com',
                        'Direct' => '🏢 Direct',
                    ])
                    ->query(fn (Builder $query, array $data): Builder => empty($data['value'])
                        ? $query
                        : $query->whereHas('booking', fn (Builder $q) => $q->where('api_source', $data['value']))
                    ),
                SelectFilter::make('building')
                    ->label('建物')
                    ->options(fn () => Building::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('booking.listing', fn (Builder $q) => $q->where('building_id', $data['value']));
                    }),
                SelectFilter::make('listing')
                    ->label('リスティング')
                    ->searchable()
                    ->options(fn () => Listing::query()
                        ->orderBy('name')
                        ->get(['id', 'name', 'display_name'])
                        ->mapWithKeys(fn (Listing $l) => [$l->id => $l->display_name ?: $l->name])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        if (empty($data['value'])) {
                            return $query;
                        }

                        return $query->whereHas('booking.listing', fn (Builder $q) => $q->where('id', $data['value']));
                    }),
                SelectFilter::make('collection_status')
                    ->label('収集状況')
                    ->options([
                        'manual' => '手動完了のみ',
                        'not_manual' => '手動完了を除く',
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return match ($data['value'] ?? null) {
                            'manual' => $query->whereNotNull('manually_completed_at'),
                            'not_manual' => $query->whereNull('manually_completed_at'),
                            default => $query,
                        };
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('copyUrl')
                    ->label('URLコピー')
                    ->icon('heroicon-o-link')
                    ->action(function (GuestRegistrationToken $record, LivewireComponent $livewire): void {
                        $url = url("/register/{$record->token}");
                        // Livewire コンポーネントに JS を積んでレスポンス後にブラウザで clipboard 書き込みさせる。
                        $livewire->js('window.navigator.clipboard.writeText('.Js::from($url).')');
                        Notification::make()
                            ->title('URL をコピーしました')
                            ->body($url)
                            ->success()
                            ->send();
                    }),
                Action::make('extend')
                    ->label('有効期限延長')
                    ->icon('heroicon-o-arrow-path')
                    ->form([
                        DateTimePicker::make('expires_at')
                            ->label('新しい有効期限')
                            ->required()
                            ->default(fn (GuestRegistrationToken $record) => $record->expires_at->copy()->addDays(7)),
                    ])
                    ->action(function (array $data, GuestRegistrationToken $record): void {
                        $record->update(['expires_at' => $data['expires_at']]);
                        Notification::make()
                            ->title('有効期限を延長しました')
                            ->success()
                            ->send();
                    }),
                Action::make('markManuallyCompleted')
                    ->label('手動完了にする')
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (GuestRegistrationToken $record) => ! $record->isManuallyCompleted())
                    ->form([
                        Textarea::make('note')
                            ->label('メモ (任意)')
                            ->placeholder('例: ゲストからメールで名簿を受領')
                            ->rows(3),
                    ])
                    ->action(function (array $data, GuestRegistrationToken $record): void {
                        $record->update([
                            'manually_completed_at' => now(),
                            'manually_completed_by' => auth()->id(),
                            'manually_completed_note' => $data['note'] ?? null,
                        ]);
                        Notification::make()
                            ->title('手動完了にしました')
                            ->success()
                            ->send();
                    }),
                Action::make('clearManuallyCompleted')
                    ->label('手動完了を解除')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (GuestRegistrationToken $record) => $record->isManuallyCompleted())
                    ->requiresConfirmation()
                    ->action(function (GuestRegistrationToken $record): void {
                        $record->update([
                            'manually_completed_at' => null,
                            'manually_completed_by' => null,
                            'manually_completed_note' => null,
                        ]);
                        Notification::make()
                            ->title('手動完了を解除しました')
                            ->info()
                            ->send();
                    }),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListGuestRegistrationTokens::route('/'),
            'view' => ViewGuestRegistrationToken::route('/{record}'),
        ];
    }

    /**
     * 予約に紐づく全ゲストの effective verdict のうち、最も注意すべきものを返す。
     * effective verdict は admin_verdict を優先し、無ければ id_image_verdict。
     * 優先度: invalid > suspicious > error > pending > ok。
     */
    private static function imageVerdictSummary(GuestRegistrationToken $token): string
    {
        $verdicts = $token->booking->guestRegistrations
            ->map(fn ($r) => $r->effectiveVerdict())
            ->filter()
            ->values();

        if ($verdicts->isEmpty()) {
            return 'none';
        }

        $priority = ['invalid', 'suspicious', 'error', 'pending', 'ok'];
        foreach ($priority as $p) {
            if ($verdicts->contains($p)) {
                return $p;
            }
        }

        return 'none';
    }

    private static function imageVerdictTooltip(GuestRegistrationToken $token): string
    {
        $lines = [];
        foreach ($token->booking->guestRegistrations as $r) {
            $verdict = $r->effectiveVerdict() ?? '—';
            $marker = $r->admin_verdict !== null ? '👤' : '🤖';
            $lines[] = sprintf('#%d %s: %s %s', $r->guest_number, $r->name, $marker, $verdict);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: CarbonImmutable, 1: int}
     */
    private static function resolvePeriod(array $data): array
    {
        $today = CarbonImmutable::today();
        $preset = $data['preset'] ?? '7days';

        return match ($preset) {
            'today' => [$today, 1],
            '14days' => [$today, 14],
            'thismonth' => [$today->startOfMonth(), $today->daysInMonth],
            'custom' => [
                ! empty($data['base_date']) ? CarbonImmutable::parse($data['base_date']) : $today,
                max(1, (int) ($data['days'] ?? 7)),
            ],
            default => [$today, 7], // '7days'
        };
    }

    /**
     * 5 区分の宿泊ステータスを文字列で返す。
     *
     * - 本日 IN
     * - 宿泊中 X/Y 日目
     * - 本日 OUT
     * - N日後 IN
     * - N日前 OUT
     */
    private static function resolveStayStatus(GuestRegistrationToken $token): string
    {
        $arrival = $token->booking->arrival;
        $departure = $token->booking->departure;

        if (! $arrival instanceof Carbon || ! $departure instanceof Carbon) {
            return '—';
        }

        $today = Carbon::today();
        $arrivalDay = $arrival->copy()->startOfDay();
        $departureDay = $departure->copy()->startOfDay();

        if ($arrivalDay->equalTo($today)) {
            return '本日 IN';
        }

        if ($departureDay->equalTo($today)) {
            return '本日 OUT';
        }

        if ($today->between($arrivalDay, $departureDay)) {
            // Carbon 3 の diffInDays は既定で符号付き float を返すため absolute: true で正の日数に固定。
            $dayNumber = (int) $arrivalDay->diffInDays($today, absolute: true) + 1;
            $totalDays = (int) $arrivalDay->diffInDays($departureDay, absolute: true) + 1;

            return sprintf('宿泊中 %d/%d 日目', $dayNumber, $totalDays);
        }

        if ($arrivalDay->greaterThan($today)) {
            $days = (int) $today->diffInDays($arrivalDay, absolute: true);

            return sprintf('%d 日後 IN', $days);
        }

        $days = (int) $departureDay->diffInDays($today, absolute: true);

        return sprintf('%d 日前 OUT', $days);
    }
}
