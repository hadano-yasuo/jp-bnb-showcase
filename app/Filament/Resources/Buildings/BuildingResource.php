<?php

namespace App\Filament\Resources\Buildings;

use App\Filament\Resources\Buildings\Pages\CreateBuilding;
use App\Filament\Resources\Buildings\Pages\EditBuilding;
use App\Filament\Resources\Buildings\Pages\ListBuildings;
use App\Models\Building;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 実世界の建物単位（Building A、Building B等）を管理する。
 *
 * beds24 の property とは独立。1 building に複数 listing が紐づく。
 * admin が UI で自由に追加・削除する。
 */
class BuildingResource extends Resource
{
    protected static ?string $model = Building::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = '建物';

    protected static ?string $modelLabel = '建物';

    protected static ?string $pluralModelLabel = '建物';

    protected static ?int $navigationSort = 20;

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
                    TextInput::make('name')
                        ->label('建物名')
                        ->required()
                        ->helperText('例: Building A、Building B'),
                    TextInput::make('display_name')
                        ->label('表示名（省略可、name を使う）'),
                    Toggle::make('is_active')
                        ->label('有効')
                        ->default(true),
                ])
                ->columns(2),
            Section::make('建物共通情報（AI 下書き生成時に参照）')
                ->description('この建物のすべてのリスティングで共通の情報。住所、緊急連絡先、建物ルール等。リスティング固有情報は各 Listing で編集。')
                ->schema([
                    Textarea::make('info_markdown')
                        ->label('建物情報')
                        ->rows(20)
                        ->autosize()
                        ->columnSpanFull(),
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
                TextColumn::make('name')->label('建物名')->sortable(),
                TextColumn::make('display_name')->label('表示名')->toggleable(),
                TextColumn::make('listings_count')
                    ->label('リスティング数')
                    ->counts('listings')
                    ->sortable(),
                IconColumn::make('info_markdown')
                    ->label('情報有り')
                    ->boolean()
                    ->getStateUsing(fn (Building $r): bool => filled($r->info_markdown)),
                IconColumn::make('is_active')->label('有効')->boolean(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListBuildings::route('/'),
            'create' => CreateBuilding::route('/create'),
            'edit' => EditBuilding::route('/{record}/edit'),
        ];
    }
}
