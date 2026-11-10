<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\User;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * 管理画面ユーザー（admin/staff）の CRUD。admin ロールのみアクセス可能。
 *
 * - パスワードは admin が入力（作成時必須、編集時は空欄なら変更なし）
 * - role は 1つだけ選択（admin or staff）。作成時未選択なら staff デフォルト
 * - 自分自身の削除は防ぐ
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'ユーザー';

    protected static ?string $modelLabel = 'ユーザー';

    protected static ?string $pluralModelLabel = 'ユーザー';

    protected static ?int $navigationSort = 30;

    /**
     * admin ロールを持つユーザーのみアクセス可能。
     */
    public static function canAccess(): bool
    {
        /** @var Authenticatable|null $user */
        $user = auth()->user();

        return $user instanceof User && $user->hasRole(User::ROLE_ADMIN);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('基本情報')
                ->schema([
                    TextInput::make('name')
                        ->label('名前')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    Select::make('roles')
                        ->label('ロール')
                        ->relationship('roles', 'name')
                        ->options(fn () => Role::query()->pluck('name', 'id')->all())
                        ->required()
                        ->default(fn () => Role::query()->where('name', User::ROLE_STAFF)->value('id'))
                        ->helperText('admin: 全機能、staff: メッセージ閲覧・返信のみ')
                        ->searchable(),
                ])
                ->columns(2),
            Section::make('パスワード')
                ->description('作成時は必須。編集時は空欄なら変更なし。')
                ->schema([
                    TextInput::make('password')
                        ->label('パスワード')
                        ->password()
                        ->revealable()
                        ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->minLength(8)
                        ->maxLength(255)
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
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label('名前')->sortable()->searchable(),
                TextColumn::make('email')->label('Email')->sortable()->searchable(),
                TextColumn::make('roles.name')
                    ->label('ロール')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        User::ROLE_ADMIN => 'primary',
                        User::ROLE_STAFF => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')->label('作成日')->dateTime('Y-m-d')->toggleable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn (User $record): bool => auth()->id() !== $record->id),
            ]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
