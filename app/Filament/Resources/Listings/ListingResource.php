<?php

namespace App\Filament\Resources\Listings;

use App\Filament\Resources\Listings\Pages\EditListing;
use App\Filament\Resources\Listings\Pages\ListListings;
use App\Models\Building;
use App\Models\Listing;
use App\Models\User;
use App\Services\Extractors\AirbnbListingExtractor;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

/**
 * リスティング（貸出単位、beds24 では room）。
 *
 * beds24 sync で自動作成される。admin は building 割当と表示名 / 情報を編集。
 * 新規作成は UI からは不可（beds24 側での作成が主導）。
 */
class ListingResource extends Resource
{
    protected static ?string $model = Listing::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'リスティング';

    protected static ?string $modelLabel = 'リスティング';

    protected static ?string $pluralModelLabel = 'リスティング';

    protected static ?int $navigationSort = 21;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::ROLE_ADMIN);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本情報')
                ->schema([
                    Select::make('building_id')
                        ->label('建物')
                        ->options(fn () => Building::query()->pluck('name', 'id')->all())
                        ->searchable()
                        ->placeholder('未割当'),
                    TextInput::make('display_name')
                        ->label('表示名')
                        ->required(),
                    TextInput::make('floor')
                        ->label('階'),
                    Toggle::make('is_active')
                        ->label('有効'),
                ])
                ->columns(2),
            Section::make('beds24 情報（sync 由来、編集不可）')
                ->schema([
                    TextInput::make('beds24_room_id')->label('beds24 room ID')->disabled(),
                    TextInput::make('beds24_property_id')->label('beds24 property ID')->disabled(),
                    TextInput::make('name')->label('beds24 上の部屋名')->disabled(),
                ])
                ->columns(3)
                ->collapsible()
                ->collapsed(),
            Section::make('リスティング固有情報（AI 下書き生成時に参照）')
                ->description('この部屋固有の情報（wifi、キーコード、階固有の注意等）。建物共通情報は Building で編集。')
                ->schema([
                    TextInput::make('airbnb_url')
                        ->label('Airbnb URL')
                        ->url()
                        ->placeholder('https://www.airbnb.jp/rooms/xxxxxxxxxx')
                        ->helperText('Airbnb の公開ページ URL。下の「Airbnb から取り込む」で概要・アメニティ等を自動抽出できます。')
                        ->suffixAction(
                            Action::make('extractFromAirbnb')
                                ->label('Airbnb から取り込む')
                                ->icon('heroicon-o-arrow-down-tray')
                                ->color('primary')
                                ->requiresConfirmation(fn (Get $get): bool => filled($get('info_markdown')))
                                ->modalHeading('リスティング情報を上書きしますか？')
                                ->modalDescription('既にリスティング情報が入力されています。Airbnb から抽出した内容で上書きします。')
                                ->modalSubmitActionLabel('上書きする')
                                ->action(function (Get $get, Set $set): void {
                                    $url = (string) $get('airbnb_url');
                                    if ($url === '') {
                                        Notification::make()->title('Airbnb URL を入力してください')->warning()->send();

                                        return;
                                    }

                                    try {
                                        $markdown = app(AirbnbListingExtractor::class)->extract($url);
                                        $set('info_markdown', $markdown);
                                        Notification::make()->title('抽出しました。プレビューを確認して保存してください。')->success()->send();
                                    } catch (Throwable $e) {
                                        Notification::make()
                                            ->title('抽出に失敗しました')
                                            ->body($e->getMessage())
                                            ->danger()
                                            ->send();
                                    }
                                })
                        )
                        ->columnSpanFull(),
                    Textarea::make('info_markdown')
                        ->label('リスティング情報')
                        ->rows(20)
                        ->autosize()
                        ->columnSpanFull(),
                    TextInput::make('wifi_password')
                        ->label('Wi-Fi パスワード')
                        ->helperText('自動送信メッセージ本文で {{ $listing->wifi_password }} として展開されます。')
                        ->maxLength(128),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->columns([
                TextColumn::make('display_name')->label('表示名')->sortable(),
                TextColumn::make('building.name')->label('建物')->sortable()->placeholder('未割当'),
                TextColumn::make('floor')->label('階')->toggleable(),
                TextColumn::make('beds24_room_id')->label('room ID')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('info_markdown')
                    ->label('情報有り')
                    ->boolean()
                    ->getStateUsing(fn (Listing $r): bool => filled($r->info_markdown)),
                IconColumn::make('is_active')->label('有効')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListListings::route('/'),
            'edit' => EditListing::route('/{record}/edit'),
        ];
    }
}
