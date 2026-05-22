<?php

namespace App\Filament\Resources\DataDictionaryEntries;

use App\Filament\Resources\DataDictionaryEntries\Pages\ManageDataDictionaryEntries;
use App\Models\DataDictionaryEntry;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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

class DataDictionaryEntryResource extends Resource
{
    protected static ?string $model = DataDictionaryEntry::class;

    protected static ?string $recordTitleAttribute = 'lookup_value';

    protected static ?string $modelLabel = 'Имя';

    protected static ?string $pluralModelLabel = 'Имена';

    protected static ?string $navigationLabel = 'Имена';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?string $navigationParentItem = 'Справочники';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('dictionary_key', DataDictionaryEntry::DICTIONARY_NAMES);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Имя')
                    ->description('Одна строка справочника: как клиент написал имя и какое полное имя нужно записать в контакт.')
                    ->schema([
                        Hidden::make('dictionary_key')
                            ->default(DataDictionaryEntry::DICTIONARY_NAMES),
                        TextInput::make('lookup_value')
                            ->label('Вариант от клиента')
                            ->placeholder('Вася')
                            ->required()
                            ->maxLength(255)
                            ->rules(['regex:/^[\p{L}][\p{L}-]{0,63}$/u'])
                            ->helperText('Одно слово без пробелов. Например: Вася, Тема, Kolya.'),
                        TextInput::make('result_value')
                            ->label('Полное имя')
                            ->placeholder('Василий')
                            ->required()
                            ->maxLength(255)
                            ->rules(['regex:/^[\p{L}][\p{L}-]{0,63}$/u'])
                            ->helperText('Это значение попадёт в переменную, например first_name.'),
                        Select::make('gender')
                            ->label('Пол')
                            ->options(DataDictionaryEntry::genderOptions())
                            ->default(DataDictionaryEntry::GENDER_UNKNOWN)
                            ->required()
                            ->native(false),
                        Select::make('language')
                            ->label('Язык')
                            ->options(DataDictionaryEntry::languageOptions())
                            ->default(DataDictionaryEntry::LANGUAGE_RU)
                            ->required()
                            ->native(false),
                        Select::make('variant_type')
                            ->label('Тип варианта')
                            ->options(DataDictionaryEntry::variantTypeOptions())
                            ->default(DataDictionaryEntry::VARIANT_TYPE_SHORT)
                            ->required()
                            ->native(false),
                        Toggle::make('auto_apply')
                            ->label('Автоматически применять')
                            ->default(true)
                            ->inline(false)
                            ->helperText('Если выключено, действие «Проверить данные» вернёт «требует уточнения».'),
                        Toggle::make('is_active')
                            ->label('Активно')
                            ->default(true)
                            ->inline(false),
                        Textarea::make('comment')
                            ->label('Комментарий')
                            ->rows(3)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ])
                    ->columns(2)
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
                TextColumn::make('lookup_value')
                    ->label('Вариант')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('result_value')
                    ->label('Полное имя')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('gender')
                    ->label('Пол')
                    ->formatStateUsing(fn (?string $state): string => DataDictionaryEntry::genderLabel($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('language')
                    ->label('Язык')
                    ->formatStateUsing(fn (?string $state): string => DataDictionaryEntry::languageLabel($state))
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('variant_type')
                    ->label('Тип')
                    ->formatStateUsing(fn (?string $state): string => DataDictionaryEntry::variantTypeLabel($state))
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('auto_apply')
                    ->label('Авто')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Активно')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('comment')
                    ->label('Комментарий')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('gender')
                    ->label('Пол')
                    ->options(DataDictionaryEntry::genderOptions()),
                SelectFilter::make('language')
                    ->label('Язык')
                    ->options(DataDictionaryEntry::languageOptions()),
                SelectFilter::make('variant_type')
                    ->label('Тип')
                    ->options(DataDictionaryEntry::variantTypeOptions()),
                TernaryFilter::make('auto_apply')
                    ->label('Автоматически применять')
                    ->placeholder('Все')
                    ->trueLabel('Только авто')
                    ->falseLabel('Только ручные'),
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->reorderableColumns()
            ->defaultSort('lookup_value')
            ->emptyStateHeading('Имена ещё не добавлены')
            ->emptyStateDescription('Добавьте строки вроде «Вася → Василий», чтобы действие «Проверить данные» работало без ИИ.')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Удалить'),
            ])
            ->toolbarActions([
                DeleteBulkAction::make()
                    ->label('Удалить выбранные'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageDataDictionaryEntries::route('/'),
        ];
    }
}
