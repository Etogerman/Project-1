<?php

namespace App\Filament\Resources\AutoReplyCategories;

use App\Filament\Resources\AutoReplyCategories\Pages\ManageAutoReplyCategories;
use App\Models\AutoReplyCategory;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class AutoReplyCategoryResource extends Resource
{
    protected static ?string $model = AutoReplyCategory::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Категория автоответа';

    protected static ?string $pluralModelLabel = 'Категории автоответов';

    protected static ?string $navigationLabel = 'Категории автоответов';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 19;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('auto_reply_categories');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('rules');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Категория')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(0)
                            ->required(),
                    ])
                    ->columnSpanFull()
                    ->columns(1),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')
                    ->label('ID')
                    ->sortable()
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('rules_count')
                    ->label('Правил')
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Создано')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Фильтры')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->defaultSort('sort_order')
            ->emptyStateHeading('Категории автоответов ещё не добавлены')
            ->emptyStateDescription('Создайте первую категорию для группировки правил.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить категорию')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить категорию')
                    ->visible(fn (AutoReplyCategory $record): bool => (int) ($record->rules_count ?? 0) === 0)
                    ->before(function (AutoReplyCategory $record): void {
                        if ($record->rules()->exists()) {
                            throw ValidationException::withMessages([
                                'category' => 'Нельзя удалить категорию, которая используется в правилах автоответа.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAutoReplyCategories::route('/'),
        ];
    }
}
