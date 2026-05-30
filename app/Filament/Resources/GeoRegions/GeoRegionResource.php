<?php

namespace App\Filament\Resources\GeoRegions;

use App\Filament\Resources\GeoRegions\Pages\ManageGeoRegions;
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

class GeoRegionResource extends Resource
{
    protected static ?string $model = GeoRegion::class;

    protected static ?string $recordTitleAttribute = 'name_ru';

    protected static ?string $modelLabel = 'Регион';

    protected static ?string $pluralModelLabel = 'Регионы';

    protected static ?string $navigationLabel = 'Регионы';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 41;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['country'])->withCount('cities');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Регион')
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
                        TextInput::make('code')
                            ->label('Код региона')
                            ->maxLength(255)
                            ->disabled(fn (?GeoRegion $record): bool => $record instanceof GeoRegion && filled($record->code))
                            ->dehydrated(fn (?GeoRegion $record): bool => ! ($record instanceof GeoRegion && filled($record->code))),
                        TextInput::make('name_ru')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('type')
                            ->label('Тип')
                            ->maxLength(255),
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
                TextColumn::make('country.name_ru')
                    ->label('Страна')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('name_ru')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Тип')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('cities_count')
                    ->label('Городов')
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
                    ->tooltip('Изменить регион')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->using(fn (array $data, GeoRegion $record): GeoRegion => static::updateRegion($record, $data)),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->tooltip('Удалить регион')
                    ->visible(fn (GeoRegion $record): bool => ! $record->cities()->exists())
                    ->before(function (GeoRegion $record): void {
                        if ($record->cities()->exists()) {
                            throw ValidationException::withMessages([
                                'region' => 'Нельзя удалить регион, пока у него есть города.',
                            ]);
                        }
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function createRegion(array $data): GeoRegion
    {
        return GeoRegion::query()->create(static::normalizeRegionData($data));
    }

    public static function updateRegion(GeoRegion $record, array $data): GeoRegion
    {
        $data = static::normalizeRegionData([
            ...$data,
            ...$record->only(['country_id']),
        ]);

        $record->fill([
            'code' => filled($record->code) ? $record->code : ($data['code'] ?? null),
            'name_ru' => $data['name_ru'],
            'name_en' => $data['name_en'] ?? null,
            'normalized_name' => $data['normalized_name'],
            'type' => $data['type'] ?? null,
            'active' => $data['active'] ?? true,
        ])->save();

        return $record;
    }

    public static function normalizeRegionData(array $data): array
    {
        $data['normalized_name'] = app(GeoTextNormalizer::class)->handle($data['name_ru'] ?? null);
        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGeoRegions::route('/'),
        ];
    }
}
