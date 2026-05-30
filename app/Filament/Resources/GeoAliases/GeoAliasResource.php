<?php

namespace App\Filament\Resources\GeoAliases;

use App\Filament\Resources\GeoAliases\Pages\ManageGeoAliases;
use App\Models\GeoAlias;
use App\Models\GeoCity;
use App\Services\Geo\GeoTextNormalizer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class GeoAliasResource extends Resource
{
    protected static ?string $model = GeoAlias::class;

    protected static ?string $recordTitleAttribute = 'alias';

    protected static ?string $modelLabel = 'Вариант написания';

    protected static ?string $pluralModelLabel = 'Варианты написания';

    protected static ?string $navigationLabel = 'Варианты написания';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 43;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['city.region.country']);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Вариант написания')
                    ->schema([
                        Select::make('city_id')
                            ->label('Город')
                            ->options(fn (): array => GeoCity::query()->with(['region', 'country'])->orderBy('name_ru')->get()->mapWithKeys(
                                fn (GeoCity $city): array => [$city->id => $city->name_ru.' · '.$city->region?->name_ru.' · '.$city->country?->name_ru]
                            )->all())
                            ->searchable()
                            ->preload()
                            ->required()
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated(fn (string $operation): bool => $operation === 'create')
                            ->native(false),
                        TextInput::make('alias')
                            ->label('Вариант')
                            ->required()
                            ->maxLength(255),
                        Select::make('alias_type')
                            ->label('Тип')
                            ->options(static::aliasTypeOptions())
                            ->default(GeoAlias::TYPE_CANONICAL)
                            ->required()
                            ->native(false),
                        TextInput::make('language')
                            ->label('Язык')
                            ->default('ru')
                            ->rule('regex:/^[a-zA-Z]{2,8}(-[a-zA-Z]{2,8})?$/')
                            ->maxLength(16),
                        TextInput::make('confidence')
                            ->label('Уверенность')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(100)
                            ->default(100)
                            ->required(),
                        Toggle::make('auto_apply')
                            ->label('Автоприменение')
                            ->default(true),
                        Toggle::make('active')
                            ->label('Активен')
                            ->default(true),
                        Textarea::make('comment')
                            ->label('Комментарий')
                            ->rows(3)
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
                    ->toggleable(),
                TextColumn::make('alias')
                    ->label('Вариант')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city.name_ru')
                    ->label('Город')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('city.region.name_ru')
                    ->label('Регион')
                    ->toggleable(),
                TextColumn::make('city.country.name_ru')
                    ->label('Страна')
                    ->toggleable(),
                TextColumn::make('alias_type')
                    ->label('Тип')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => static::aliasTypeOptions()[$state] ?? (string) $state),
                TextColumn::make('confidence')
                    ->label('Уверенность')
                    ->sortable(),
                IconColumn::make('auto_apply')
                    ->label('Авто')
                    ->boolean(),
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
            ->defaultSort('alias')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить вариант')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->using(fn (array $data, GeoAlias $record): GeoAlias => static::updateAlias($record, $data)),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->extraModalWindowAttributes(['class' => 'ac-geo-form-modal'])
                    ->tooltip('Удалить вариант'),
            ])
            ->toolbarActions([]);
    }

    /**
     * @return array<string,string>
     */
    public static function aliasTypeOptions(): array
    {
        return [
            GeoAlias::TYPE_CANONICAL => 'Полное',
            GeoAlias::TYPE_SHORT => 'Сокращение',
            GeoAlias::TYPE_TRANSLIT => 'Транслит',
            GeoAlias::TYPE_CASE_FORM => 'Падеж',
            GeoAlias::TYPE_OLD_NAME => 'Старое название',
            GeoAlias::TYPE_SLANG => 'Разговорное',
            GeoAlias::TYPE_TYPO => 'Опечатка',
            GeoAlias::TYPE_FOREIGN_NAME => 'Иностранное',
        ];
    }

    public static function createAlias(array $data): GeoAlias
    {
        return GeoAlias::query()->create(static::normalizeAliasData($data));
    }

    public static function updateAlias(GeoAlias $record, array $data): GeoAlias
    {
        $data = static::normalizeAliasData([
            ...$data,
            ...$record->only(['city_id']),
        ]);

        $record->fill([
            'alias' => $data['alias'],
            'normalized_alias' => $data['normalized_alias'],
            'language' => $data['language'] ?? 'ru',
            'alias_type' => $data['alias_type'] ?? GeoAlias::TYPE_CANONICAL,
            'confidence' => $data['confidence'] ?? 100,
            'auto_apply' => $data['auto_apply'] ?? true,
            'active' => $data['active'] ?? true,
            'comment' => $data['comment'] ?? null,
        ])->save();

        return $record;
    }

    public static function normalizeAliasData(array $data): array
    {
        $data['normalized_alias'] = app(GeoTextNormalizer::class)->handle($data['alias'] ?? null);
        $data['language'] = filled($data['language'] ?? null) ? (string) $data['language'] : 'ru';
        $data['alias_type'] = $data['alias_type'] ?? GeoAlias::TYPE_CANONICAL;
        $data['confidence'] = (int) ($data['confidence'] ?? 100);
        $data['auto_apply'] = (bool) ($data['auto_apply'] ?? true);
        $data['active'] = (bool) ($data['active'] ?? true);

        return $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageGeoAliases::route('/'),
        ];
    }
}
