<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Tag;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class TagResource extends Resource
{
    protected static ?string $model = Tag::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Тег';

    protected static ?string $pluralModelLabel = 'Теги';

    protected static ?string $navigationLabel = 'Теги';

    protected static string|UnitEnum|null $navigationGroup = 'Аудитория';

    protected static ?int $navigationSort = 20;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withCount('contacts')
            ->withUsedInRulesCount();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Тег')
                    ->description('Название и цветовая метка для сегментации контактов.')
                    ->extraAttributes(['class' => 'ac-tag-form-section'])
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        ToggleButtons::make('color')
                            ->label('Цвет')
                            ->options(Tag::colorOptions())
                            ->colors([
                                Tag::COLOR_GRAY => 'gray',
                                Tag::COLOR_PRIMARY => 'primary',
                                Tag::COLOR_SUCCESS => 'success',
                                Tag::COLOR_WARNING => 'warning',
                                Tag::COLOR_DANGER => 'danger',
                            ])
                            ->required()
                            ->inline()
                            ->extraAttributes(['class' => 'ac-tag-color-picker']),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->inline(false)
                            ->extraAttributes(['class' => 'ac-tag-form-toggle']),
                    ])
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
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Код')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('color')
                    ->label('Цвет')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => Tag::colorOptions()[$state] ?? $state)
                    ->color(fn (string $state): string => $state),
                TextColumn::make('contacts_count')
                    ->label('Контакты')
                    ->badge()
                    ->sortable()
                    ->color(fn (int $state): string => $state > 0 ? 'primary' : 'gray')
                    ->url(fn (Tag $record): ?string => (int) ($record->contacts_count ?? 0) > 0
                        ? ContactResource::getUrl(parameters: ['tag' => $record->getKey()])
                        : null),
                TextColumn::make('used_in_rules_count')
                    ->label('Используют')
                    ->badge()
                    ->sortable()
                    ->state(fn (Tag $record): int => (int) ($record->getAttribute('used_in_rules_count') ?? 0))
                    ->color(fn (Tag $record): string => (int) ($record->getAttribute('used_in_rules_count') ?? 0) > 0 ? 'primary' : 'gray')
                    ->url(fn (Tag $record): ?string => (int) ($record->getAttribute('used_in_rules_count') ?? 0) > 0
                        ? AutoReplyRuleResource::getUrl(parameters: ['tag' => $record->getKey()])
                        : null),
                TextColumn::make('is_active')
                    ->label('Статус')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключён')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Создан')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Статус')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Фильтры')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->recordActionsColumnLabel('Кнопки')
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Теги ещё не добавлены')
            ->emptyStateDescription('Создайте первый тег для сегментации контактов.')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить тег')
                    ->modalWidth(Width::Medium)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-tag-form-modal']),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить тег')
                    ->visible(fn (Tag $record): bool => (int) ($record->contacts_count ?? 0) === 0 && (int) ($record->getAttribute('used_in_rules_count') ?? 0) === 0)
                    ->before(function (Tag $record): void {
                        if ($record->contacts()->exists() || $record->isUsedInAutoReplyRules()) {
                            throw ValidationException::withMessages([
                                'tag' => 'Нельзя удалить тег, который назначен контактам или используется в правилах автоответа.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTags::route('/'),
        ];
    }
}
