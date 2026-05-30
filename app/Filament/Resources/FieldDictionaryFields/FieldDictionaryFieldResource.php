<?php

namespace App\Filament\Resources\FieldDictionaryFields;

use App\Filament\Resources\FieldDictionaryFields\Pages\ManageFieldDictionaryFields;
use App\Models\FieldDictionaryField;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class FieldDictionaryFieldResource extends Resource
{
    protected static ?string $model = FieldDictionaryField::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Поле';

    protected static ?string $pluralModelLabel = 'Справочник полей';

    protected static ?string $navigationLabel = 'Справочник полей';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationParentItem = 'Справочники';

    protected static ?int $navigationSort = 19;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Поле')
                    ->description('Справочник описывает поля объектов. В этом срезе конструктор и runtime его ещё не используют.')
                    ->schema([
                        Select::make('entity')
                            ->label('Объект')
                            ->options(FieldDictionaryField::entityOptions())
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->disabled(fn (?FieldDictionaryField $record): bool => $record?->exists ?? false)
                            ->dehydrated(true),
                        TextInput::make('field_key')
                            ->label('Ключ поля')
                            ->helperText('Латиница, цифры и подчёркивания. После создания ключ нельзя менять.')
                            ->required()
                            ->rules(['regex:/^[a-z][a-z0-9_]*$/'])
                            ->maxLength(128)
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule) => $rule->where('entity', request()->input('data.entity')))
                            ->disabled(fn (?FieldDictionaryField $record): bool => $record?->exists ?? false)
                            ->dehydrated(true),
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->label('Тип поля')
                            ->options(FieldDictionaryField::typeOptions())
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false)
                            ->live()
                            ->disabled(fn (?FieldDictionaryField $record): bool => (bool) ($record?->is_system ?? false))
                            ->dehydrated(true),
                        Toggle::make('is_multiple')
                            ->label('Множественное поле')
                            ->inline(false)
                            ->disabled(fn (?FieldDictionaryField $record): bool => $record?->exists ?? false)
                            ->dehydrated(true)
                            ->helperText('Включено, если у одного объекта может быть несколько значений этого поля. Например: телефоны или email.'),
                        Select::make('source_field_key')
                            ->label('Поле источника')
                            ->helperText('Например: у поля «Имя» источником может быть «Откуда знаем имя».')
                            ->options(fn (Get $get, ?FieldDictionaryField $record = null): array => static::sourceFieldOptions(
                                (string) $get('entity'),
                                $record?->field_key,
                            ))
                            ->searchable()
                            ->native(false)
                            ->placeholder('Нет')
                            ->disabled(fn (?FieldDictionaryField $record): bool => (bool) ($record?->is_system ?? false))
                            ->dehydrated(true),
                        TextInput::make('sort_order')
                            ->label('Порядок')
                            ->numeric()
                            ->default(100)
                            ->required(),
                        Toggle::make('is_system')
                            ->label('Системное поле')
                            ->inline(false)
                            ->disabled()
                            ->dehydrated(true)
                            ->helperText('Системные поля нельзя удалить, а ключ, тип и поле источника нельзя менять.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Значения списка')
                    ->description('Для системных значений можно менять подпись, но нельзя менять технический код или удалять значение.')
                    ->hidden(fn (Get $get): bool => $get('type') !== FieldDictionaryField::TYPE_SELECT)
                    ->schema([
                        Repeater::make('options')
                            ->label('Доступные значения')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('value')
                                    ->label('Код')
                                    ->required()
                                    ->rules(['alpha_dash:ascii'])
                                    ->maxLength(128)
                                    ->disabled(fn (Get $get): bool => (bool) $get('is_system'))
                                    ->dehydrated(true),
                                TextInput::make('label')
                                    ->label('Название')
                                    ->required()
                                    ->maxLength(255),
                                Toggle::make('is_system')
                                    ->label('Системное')
                                    ->inline(false)
                                    ->disabled()
                                    ->dehydrated(true),
                            ])
                            ->columns(3)
                            ->defaultItems(0)
                            ->addActionLabel('Добавить значение'),
                    ])
                    ->columnSpanFull(),
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
                TextColumn::make('entity')
                    ->label('Объект')
                    ->formatStateUsing(fn (?string $state): string => FieldDictionaryField::entityLabel($state))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('field_key')
                    ->label('Ключ')
                    ->searchable()
                    ->copyable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => FieldDictionaryField::typeLabel($state))
                    ->badge()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('source_field_key')
                    ->label('Источник')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('options')
                    ->label('Значения')
                    ->state(fn (FieldDictionaryField $record): string => $record->optionsSummary())
                    ->limit(80)
                    ->tooltip(fn (FieldDictionaryField $record): ?string => $record->optionsSummary() !== '—' ? $record->optionsSummary() : null)
                    ->toggleable(),
                IconColumn::make('is_system')
                    ->label('Системное')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('sort_order')
                    ->label('Порядок')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('entity')
                    ->label('Объект')
                    ->options(FieldDictionaryField::entityOptions()),
                SelectFilter::make('type')
                    ->label('Тип')
                    ->options(FieldDictionaryField::typeOptions()),
                TernaryFilter::make('is_system')
                    ->label('Системность')
                    ->placeholder('Все')
                    ->trueLabel('Системные')
                    ->falseLabel('Пользовательские'),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->defaultSort('sort_order')
            ->emptyStateHeading('Поля ещё не настроены')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip(fn (FieldDictionaryField $record): string => $record->is_system
                        ? 'Системное поле нельзя удалить'
                        : ($record->isReferencedAsSource() ? 'Сначала уберите связь с полем-источником' : 'Удалить'))
                    ->disabled(fn (FieldDictionaryField $record): bool => $record->is_system || $record->isReferencedAsSource())
                    ->requiresConfirmation(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFieldDictionaryFields::route('/'),
        ];
    }

    /**
     * @return array<string, string>
     */
    protected static function sourceFieldOptions(string $entity, ?string $currentFieldKey = null): array
    {
        if (! array_key_exists($entity, FieldDictionaryField::entityOptions())) {
            return [];
        }

        return FieldDictionaryField::query()
            ->where('entity', $entity)
            ->when(filled($currentFieldKey), fn (Builder $query): Builder => $query->where('field_key', '!=', $currentFieldKey))
            ->ordered()
            ->pluck('name', 'field_key')
            ->all();
    }
}
