<?php

namespace App\Filament\Resources\GeoCities;

use App\Filament\Resources\GeoCities\Pages\ManageGeoCities;
use App\Models\GeoCity;
use App\Models\GeoCountry;
use App\Models\GeoRegion;
use App\Services\Geo\GeoTextNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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

class GeoCityResource extends Resource
{
    protected static ?string $model = GeoCity::class;

    protected static ?string $recordTitleAttribute = 'name_ru';

    protected static ?string $modelLabel = 'Город';

    protected static ?string $pluralModelLabel = 'Города';

    protected static ?string $navigationLabel = 'Города';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 42;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['country', 'region'])->withCount('aliases');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Город')
                    ->schema([
                        Select::make('country_id')
                            ->label('Страна')
                            ->options(fn (): array => GeoCountry::query()->orderBy('name_ru')->pluck('name_ru', 'id')->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->native(false),
                        Select::make('region_id')
                            ->label('Регион')
                            ->options(fn (): array => GeoRegion::query()->with('country')->orderBy('name_ru')->get()->mapWithKeys(
                                fn (GeoRegion $region): array => [$region->id => $region->name_ru.' · '.$region->country?->name_ru]
                            )->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->native(false),
                        TextInput::make('name_ru')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name_en')
                            ->label('Название EN')
                            ->maxLength(255),
                        TextInput::make('population')
                            ->label('Население')
                            ->numeric()
                            ->minValue(0),
                        TextInput::make('lat')
                            ->label('Широта')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('lon')
                            ->label('Долгота')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                        TextInput::make('timezone')
                            ->label('Часовой пояс')
                            ->maxLength(64),
                        Toggle::make('active')
                            ->label('Активен')
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
                TextColumn::make('name_ru')
                    ->label('Город')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('region.name_ru')
                    ->label('Регион')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('country.name_ru')
                    ->label('Страна')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('population')
                    ->label('Население')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('aliases_count')
                    ->label('Alias')
                    ->badge()
                    ->sortable(),
                IconColumn::make('active')
                    ->label('Активен')
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
                    ->tooltip('Изменить город')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->using(fn (array $data, GeoCity $record): GeoCity => static::updateCity($record, $data)),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
                    ->tooltip('Удалить город')
                    ->visible(fn (GeoCity $record): bool => ! $record->aliases()->exists())
                    ->before(function (GeoCity $record): void {
                        if ($record->aliases()->exists()) {
                            throw ValidationException::withMessages([
                                'city' => 'Нельзя удалить город, пока у него есть варианты написания.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function createCity(array $data): GeoCity
    {
        return GeoCity::query()->create(static::normalizeCityData($data));
    }

    public static function updateCity(GeoCity $record, array $data): GeoCity
    {
        $data = static::normalizeCityData([
            ...$data,
            ...$record->only(['country_id', 'region_id']),
        ]);

        $record->fill([
            'name_ru' => $data['name_ru'],
            'name_en' => $data['name_en'] ?? null,
            'normalized_name' => $data['normalized_name'],
            'population' => $data['population'] ?? null,
            'lat' => $data['lat'] ?? null,
            'lon' => $data['lon'] ?? null,
            'timezone' => $data['timezone'] ?? null,
            'active' => $data['active'] ?? true,
        ])->save();

        return $record;
    }

    public static function normalizeCityData(array $data): array
    {
        $data['normalized_name'] = app(GeoTextNormalizer::class)->handle($data['name_ru'] ?? null);
        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGeoCities::route('/'),
        ];
    }
}
