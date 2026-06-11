<?php

namespace App\Filament\Resources\AiPricingRates;

use App\Filament\Resources\AiPricingRates\Pages\ManageAiPricingRates;
use App\Models\AiPricingRate;
use App\Models\AiProcessor;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
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
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class AiPricingRateResource extends Resource
{
    protected static ?string $model = AiPricingRate::class;

    protected static ?string $recordTitleAttribute = 'model';

    protected static ?string $modelLabel = 'ИИ-тариф';

    protected static ?string $pluralModelLabel = 'ИИ-тарифы';

    protected static ?string $navigationLabel = 'ИИ-тарифы';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 21;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCurrencyDollar;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('ai_pricing_rates')
            && auth()->user()?->canDebugAnalytics() === true;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Тариф')
                ->schema([
                    Select::make('provider')
                        ->label('Провайдер')
                        ->options(AiProcessor::providerOptions())
                        ->default(AiProcessor::PROVIDER_GEMINI)
                        ->required()
                        ->native(false),
                    TextInput::make('model')
                        ->label('Модель')
                        ->placeholder('gemini-2.5-flash')
                        ->required()
                        ->maxLength(255),
                    DatePicker::make('effective_from')
                        ->label('Действует с')
                        ->required()
                        ->default(now()),
                    Select::make('currency')
                        ->label('Валюта')
                        ->options([AiPricingRate::CURRENCY_USD => 'USD'])
                        ->default(AiPricingRate::CURRENCY_USD)
                        ->required()
                        ->selectablePlaceholder(false)
                        ->native(false),
                    TextInput::make('input_price_per_1m_tokens')
                        ->label('Input / 1M')
                        ->numeric()
                        ->step('0.00000001')
                        ->default(0)
                        ->required(),
                    TextInput::make('output_price_per_1m_tokens')
                        ->label('Output / 1M')
                        ->numeric()
                        ->step('0.00000001')
                        ->default(0)
                        ->required(),
                    TextInput::make('thinking_price_per_1m_tokens')
                        ->label('Thinking / 1M')
                        ->numeric()
                        ->step('0.00000001')
                        ->default(0)
                        ->required(),
                    Toggle::make('is_active')
                        ->label('Активен')
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
                TextColumn::make('provider')
                    ->label('Провайдер')
                    ->formatStateUsing(fn (string $state): string => AiProcessor::providerOptions()[$state] ?? $state)
                    ->badge()
                    ->sortable(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('input_price_per_1m_tokens')
                    ->label('Input / 1M')
                    ->sortable(),
                TextColumn::make('output_price_per_1m_tokens')
                    ->label('Output / 1M')
                    ->sortable(),
                TextColumn::make('thinking_price_per_1m_tokens')
                    ->label('Thinking / 1M')
                    ->sortable(),
                TextColumn::make('currency')
                    ->label('Валюта')
                    ->badge()
                    ->sortable(),
                TextColumn::make('effective_from')
                    ->label('С даты')
                    ->date('d.m.Y')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('effective_from', 'desc')
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
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiPricingRates::route('/'),
        ];
    }
}
