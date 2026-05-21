<?php

namespace App\Filament\Resources\AiProcessors;

use App\Filament\Resources\AiProcessors\Pages\ManageAiProcessors;
use App\Models\AiProcessor;
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
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use UnitEnum;

class AiProcessorResource extends Resource
{
    protected static ?string $model = AiProcessor::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'ИИ-обработчик';

    protected static ?string $pluralModelLabel = 'ИИ-обработчики';

    protected static ?string $navigationLabel = 'ИИ-обработчики';

    protected static string|UnitEnum|null $navigationGroup = 'Настройки';

    protected static ?int $navigationSort = 19;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function shouldRegisterNavigation(): bool
    {
        return SchemaFacade::hasTable('ai_processors');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Обработчик')
                    ->description('Система пробует активные обработчики по порядку. Если первый недоступен, запрос уходит следующему.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Название')
                            ->placeholder('Gemini основной')
                            ->required()
                            ->maxLength(255),
                        Select::make('provider')
                            ->label('Провайдер')
                            ->options(AiProcessor::providerOptions())
                            ->default(AiProcessor::PROVIDER_GEMINI)
                            ->required()
                            ->selectablePlaceholder(false)
                            ->native(false),
                        TextInput::make('model')
                            ->label('Модель')
                            ->placeholder('gemini-2.5-flash')
                            ->maxLength(255),
                        TextInput::make('base_url')
                            ->label('Адрес API')
                            ->placeholder('https://generativelanguage.googleapis.com/v1beta')
                            ->url()
                            ->maxLength(255),
                        TextInput::make('credentials.api_key')
                            ->label('Ключ API')
                            ->password()
                            ->revealable()
                            ->helperText('При редактировании оставьте пустым, чтобы сохранить текущий ключ. Если ключ не задан здесь, Gemini использует GEMINI_API_KEY из окружения.')
                            ->afterStateHydrated(function (TextInput $component, string $operation): void {
                                if ($operation === 'edit') {
                                    $component->state(null);
                                }
                            })
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? trim($state) : null)
                            ->dehydrated(fn (?string $state, string $operation): bool => $operation === 'create' || filled($state))
                            ->maxLength(65535)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->inline(false),
                        TextInput::make('priority')
                            ->label('Порядок')
                            ->numeric()
                            ->default(100)
                            ->required()
                            ->helperText('Чем меньше число, тем раньше система попробует обработчик.'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Параметры запроса')
                    ->schema([
                        TextInput::make('timeout_seconds')
                            ->label('Таймаут, сек')
                            ->numeric()
                            ->default(30)
                            ->required(),
                        TextInput::make('temperature')
                            ->label('Температура')
                            ->numeric()
                            ->step('0.01')
                            ->default(0.2)
                            ->required(),
                        TextInput::make('max_output_tokens')
                            ->label('Макс. токенов ответа')
                            ->numeric()
                            ->default(512)
                            ->required(),
                        TextInput::make('thinking_budget')
                            ->label('Thinking budget')
                            ->numeric()
                            ->default(0)
                            ->required(),
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
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('provider')
                    ->label('Провайдер')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AiProcessor::providerOptions()[$state] ?? $state)
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('model')
                    ->label('Модель')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Активен')
                    ->boolean()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('priority')
                    ->label('Порядок')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('api_key_status')
                    ->label('Ключ API')
                    ->state(fn (AiProcessor $record): string => $record->apiKeyStatusLabel())
                    ->badge()
                    ->toggleable(),
                TextColumn::make('timeout_seconds')
                    ->label('Таймаут')
                    ->suffix(' сек')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('last_failed_at')
                    ->label('Последняя ошибка')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('last_error_message')
                    ->label('Текст ошибки')
                    ->limit(60)
                    ->tooltip(fn (?string $state): ?string => filled($state) ? $state : null)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Обновлено')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->reorderableColumns()
            ->defaultSort('priority')
            ->emptyStateHeading('ИИ-обработчики ещё не добавлены')
            ->emptyStateDescription('Добавьте хотя бы один активный обработчик, чтобы ИИ-анализ мог выполнять запросы.')
            ->recordActions([
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить')
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->fillForm(fn (AiProcessor $record): array => [
                        'name' => $record->name,
                        'provider' => $record->provider,
                        'model' => $record->model,
                        'base_url' => $record->base_url,
                        'credentials' => [
                            'api_key' => null,
                        ],
                        'is_active' => $record->is_active,
                        'priority' => $record->priority,
                        'timeout_seconds' => $record->timeout_seconds,
                        'temperature' => $record->temperature,
                        'max_output_tokens' => $record->max_output_tokens,
                        'thinking_budget' => $record->thinking_budget,
                    ])
                    ->using(function (array $data, AiProcessor $record): void {
                        $record->update(static::mutateProcessorData($data, $record));
                    }),
                DeleteAction::make()
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Удалить'),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAiProcessors::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function mutateProcessorData(array $data, ?AiProcessor $record = null): array
    {
        $apiKey = trim((string) data_get($data, 'credentials.api_key', ''));
        $credentials = $record?->credentials ?? [];

        if ($apiKey !== '') {
            Arr::set($credentials, 'api_key', $apiKey);
            Arr::set($data, 'credentials', $credentials);

            return $data;
        }

        Arr::forget($data, 'credentials');

        return $data;
    }
}
