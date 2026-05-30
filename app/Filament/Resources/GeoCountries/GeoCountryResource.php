<?php

namespace App\Filament\Resources\GeoCountries;

use App\Filament\Resources\GeoCountries\Pages\ManageGeoCountries;
use App\Models\GeoCountry;
use App\Services\Geo\GeoTextNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class GeoCountryResource extends Resource
{
    protected static ?string $model = GeoCountry::class;

    protected static ?string $recordTitleAttribute = 'name_ru';

    protected static ?string $modelLabel = 'Страна';

    protected static ?string $pluralModelLabel = 'Страны';

    protected static ?string $navigationLabel = 'Страны';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 40;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeEuropeAfrica;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['regions', 'cities']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Страна')
                    ->schema([
                        TextInput::make('iso2')
                            ->label('ISO2')
                            ->required()
                            ->maxLength(2)
                            ->rule('regex:/^[A-Za-z]{2}$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : null),
                        TextInput::make('iso3')
                            ->label('ISO3')
                            ->required()
                            ->maxLength(3)
                            ->rule('regex:/^[A-Za-z]{3}$/')
                            ->unique(ignoreRecord: true)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? mb_strtoupper(trim($state)) : null),
                        TextInput::make('name_ru')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_en')
                            ->label('Название EN')
                            ->maxLength(255),
                        Toggle::make('active')
                            ->label('Активна')
                            ->default(true),
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
                    ->toggleable(),
                TextColumn::make('iso2')
                    ->label('ISO2')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('iso3')
                    ->label('ISO3')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name_ru')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('regions_count')
                    ->label('Регионов')
                    ->badge()
                    ->sortable(),
                TextColumn::make('cities_count')
                    ->label('Городов')
                    ->badge()
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Активна')
                    ->boolean()
                    ->sortable(),
            ])
            ->filtersTriggerAction(fn (Action $action): Action => $action->tooltip('Фильтры'))
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(fn (Action $action): Action => $action->tooltip('Столбцы'))
            ->defaultSort('name_ru')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить страну')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->using(fn (array $data, GeoCountry $record): GeoCountry => static::updateCountry($record, $data)),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить страну')
                    ->visible(fn (GeoCountry $record): bool => ! $record->regions()->exists() && ! $record->cities()->exists())
                    ->before(function (GeoCountry $record): void {
                        if ($record->regions()->exists() || $record->cities()->exists()) {
                            throw ValidationException::withMessages([
                                'country' => 'Нельзя удалить страну, пока у неё есть регионы или города.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function createCountry(array $data): GeoCountry
    {
        return GeoCountry::query()->create(static::normalizeCountryData($data));
    }

    public static function updateCountry(GeoCountry $record, array $data): GeoCountry
    {
        $data = static::normalizeCountryData([
            ...$data,
            ...$record->only(['iso2', 'iso3']),
        ]);

        $record->fill([
            'name_ru' => $data['name_ru'],
            'name_en' => $data['name_en'] ?? null,
            'normalized_name' => $data['normalized_name'],
            'active' => $data['active'] ?? true,
        ])->save();

        return $record;
    }

    public static function normalizeCountryData(array $data): array
    {
        $data['iso2'] = mb_strtoupper(trim((string) ($data['iso2'] ?? '')));
        $data['iso3'] = mb_strtoupper(trim((string) ($data['iso3'] ?? '')));
        $data['normalized_name'] = app(GeoTextNormalizer::class)->handle($data['name_ru'] ?? null);
        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGeoCountries::route('/'),
        ];
    }
}
