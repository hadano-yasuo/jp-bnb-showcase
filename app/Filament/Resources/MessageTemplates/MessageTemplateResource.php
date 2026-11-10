<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Resources\AutoMessageRules\AutoMessageRuleResource;
use App\Filament\Resources\MessageTemplates\Pages\CreateMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\EditMessageTemplate;
use App\Filament\Resources\MessageTemplates\Pages\ListMessageTemplates;
use App\Models\Listing;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component as LivewireComponent;

/**
 * 返信用の定型文 CRUD。日本語で本文を保存し、会話詳細画面から選んで挿入。
 * 送信時は既存の翻訳フロー（TranslationService）でゲスト言語に自動翻訳される。
 */
class MessageTemplateResource extends Resource
{
    protected static ?string $model = MessageTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-duplicate';

    protected static ?string $navigationLabel = '定型文';

    protected static ?string $modelLabel = '定型文';

    protected static ?string $pluralModelLabel = '定型文';

    protected static ?int $navigationSort = 17;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make()
                    ->schema([
                        Section::make('基本情報')
                            ->schema([
                                TextInput::make('name')
                                    ->label('ラベル')
                                    ->helperText('選択メニューに表示される短い名前（例: wifi案内、レイトチェックアウトOK）')
                                    ->required()
                                    ->maxLength(100),
                                Toggle::make('is_active')
                                    ->label('有効')
                                    ->default(true),
                            ])
                            ->columns(2),
                        Section::make('適用対象リスティング')
                            ->description('未選択の場合は全リスティング対象（共通テンプレ）。特定リスティングを選ぶとその会話でのみ選択肢に出ます。')
                            ->schema([
                                Select::make('listings')
                                    ->label('リスティング（複数選択可、空なら全対象）')
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
                                    ->columnSpanFull(),
                            ]),
                    ])
                    ->extraAttributes(['class' => 'auto-message-form-left'])
                    ->columnSpan(['lg' => 4]),

                Group::make()
                    ->schema([AutoMessageRuleResource::bodySection()])
                    ->columnSpan(['lg' => 6]),
            ])
            ->columns(10);
    }

    /**
     * リスティング絞り込み中は並び替え無効。表示されている行だけ sort_order を
     * 振り直すため、絞り込み中に並び替えると非表示行と番号が重複して壊れる。
     */
    private static function isReorderAllowed(?LivewireComponent $livewire): bool
    {
        if ($livewire === null) {
            return true;
        }
        $filterValue = $livewire->tableFilters['listing']['value'] ?? null;

        return $filterValue === null || $filterValue === '';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->persistFiltersInSession()
            ->persistSortInSession()
            ->persistSearchInSession()
            ->reorderable('sort_order', fn (?LivewireComponent $livewire = null): bool => self::isReorderAllowed($livewire))
            ->reorderRecordsTriggerAction(
                fn (Action $action, bool $isReordering, ?LivewireComponent $livewire = null): Action => $action
                    ->label(match (true) {
                        ! self::isReorderAllowed($livewire) => '並び替え不可（フィルター解除してください）',
                        $isReordering => '並び替え終了',
                        default => '並び替え（ドラッグ＆ドロップで自動保存）',
                    })
                    ->disabled(! self::isReorderAllowed($livewire))
            )
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->label('ラベル')->sortable()->searchable(),
                TextColumn::make('body')
                    ->label('本文')
                    ->limit(80)
                    ->tooltip(fn (MessageTemplate $r): string => $r->body)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('listings.display_name')
                    ->label('対象リスティング')
                    ->badge()
                    ->placeholder('全リスティング'),
                IconColumn::make('is_active')->label('有効')->boolean(),
            ])
            ->filters([
                SelectFilter::make('listing')
                    ->label('リスティング')
                    ->options(function () {
                        $options = ['__universal' => '全リスティング（未指定）'];
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
                        if ($value === '__universal') {
                            return $query->whereDoesntHave('listings');
                        }

                        return $query->whereHas('listings', fn ($q) => $q->where('listings.id', (int) $value));
                    }),
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
            'index' => ListMessageTemplates::route('/'),
            'create' => CreateMessageTemplate::route('/create'),
            'edit' => EditMessageTemplate::route('/{record}/edit'),
        ];
    }
}
