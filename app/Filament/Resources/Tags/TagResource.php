<?php

namespace App\Filament\Resources\Tags;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Models\Tag;
use BackedEnum;
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
        return parent::getEloquentQuery()->withCount('contacts');
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
                    ->label('Назначений')
                    ->badge()
                    ->sortable(),
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
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Теги ещё не добавлены')
            ->emptyStateDescription('Создайте первый тег для сегментации контактов.')
            ->recordActions([
                EditAction::make()
                    ->modalWidth(Width::Medium)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-tag-form-modal']),
                DeleteAction::make()
                    ->visible(fn (Tag $record): bool => (int) ($record->contacts_count ?? 0) === 0)
                    ->before(function (Tag $record): void {
                        if ($record->contacts()->exists()) {
                            throw ValidationException::withMessages([
                                'tag' => 'Нельзя удалить тег, который уже назначен контактам.',
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
