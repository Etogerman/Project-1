<?php

namespace App\Filament\Resources\Scenarios;

use App\Filament\Resources\Scenarios\Pages\ManageScenarios;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\ArchiveScenarioAction;
use App\Services\Scenarios\CreateNextScenarioDraftAction;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ScenarioResource extends Resource
{
    protected static ?string $model = Scenario::class;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'Сценарий';

    protected static ?string $pluralModelLabel = 'Сценарии';

    protected static ?string $navigationLabel = 'Сценарии';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 15;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'draftVersion',
            'publishedVersion',
            'versions' => fn (Builder $query): Builder => $query->orderByDesc('version_number'),
        ]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Сценарий')
                    ->description('Глобальный сценарий системы без привязки к конкретному каналу.')
                    ->extraAttributes(['class' => 'ac-scenario-form-section'])
                    ->schema([
                        TextInput::make('code')
                            ->label('Код')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Код задаётся при создании и дальше не меняется.')
                            ->disabled(fn (?Scenario $record): bool => $record !== null)
                            ->dehydrated(fn (?Scenario $record): bool => $record === null)
                            ->unique(ignoreRecord: true),
                        TextInput::make('name')
                            ->label('Название')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_active')
                            ->label('Активен')
                            ->default(true)
                            ->disabled(fn (?Scenario $record): bool => (bool) $record?->is_archived)
                            ->inline(false),
                        Placeholder::make('scenario_status')
                            ->label('Состояние')
                            ->hidden(fn (?Scenario $record): bool => $record === null)
                            ->content(fn (?Scenario $record): string => static::formatScenarioStatus($record)),
                    ])
                    ->columns(2),
                Section::make('Версии')
                    ->description('На этом шаге схема сценария хранится как JSON черновика.')
                    ->extraAttributes(['class' => 'ac-scenario-form-section'])
                    ->hidden(fn (?Scenario $record): bool => $record === null)
                    ->schema([
                        Placeholder::make('published_version_label')
                            ->label('Опубликованная версия')
                            ->content(fn (?Scenario $record): string => static::formatVersionLabel($record?->publishedVersion)),
                        Placeholder::make('draft_version_label')
                            ->label('Черновик')
                            ->content(fn (?Scenario $record): string => static::formatVersionLabel($record?->draftVersion)),
                        Placeholder::make('versions_overview')
                            ->label('История версий')
                            ->columnSpanFull()
                            ->content(fn (?Scenario $record): HtmlString => static::buildVersionsOverview($record)),
                        Textarea::make('draft_schema_payload_json')
                            ->label('Схема черновика (JSON)')
                            ->hidden(fn (?Scenario $record): bool => $record?->draftVersion === null)
                            ->rows(14)
                            ->columnSpanFull()
                            ->helperText('Редактируется только активный черновик. Опубликованная версия остаётся неизменяемой.')
                            ->afterStateHydrated(function (Textarea $component, ?Scenario $record): void {
                                $component->state(
                                    static::encodeSchemaPayload(
                                        $record?->draftVersion?->schema_payload ?? [],
                                    ),
                                );
                            }),
                        Placeholder::make('no_draft_state')
                            ->hiddenLabel()
                            ->visible(fn (?Scenario $record): bool => $record?->draftVersion === null)
                            ->content('Активного черновика нет. Сначала создайте новый черновик из опубликованной версии.'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Код')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('published_version')
                    ->label('Опубликована')
                    ->state(fn (Scenario $record): string => static::formatVersionLabel($record->publishedVersion))
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->publishedVersion instanceof ScenarioVersion ? 'success' : 'gray'),
                TextColumn::make('draft_version')
                    ->label('Черновик')
                    ->state(fn (Scenario $record): string => static::formatVersionLabel($record->draftVersion))
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->draftVersion instanceof ScenarioVersion ? 'warning' : 'gray'),
                TextColumn::make('versions_summary')
                    ->label('Версии')
                    ->state(fn (Scenario $record): string => static::formatVersionsSummary($record))
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (Scenario $record): string => static::formatVersionsSummary($record)),
                TextColumn::make('is_active')
                    ->label('Активность')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключён')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable(),
                TextColumn::make('is_archived')
                    ->label('Архив')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Архивный' : 'Рабочий')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary')
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Активность')
                    ->placeholder('Все')
                    ->trueLabel('Только активные')
                    ->falseLabel('Только отключённые'),
                TernaryFilter::make('is_archived')
                    ->label('Архив')
                    ->placeholder('Все')
                    ->trueLabel('Только архивные')
                    ->falseLabel('Только рабочие'),
            ])
            ->filtersTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Фильтры')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->columnManager()
            ->deferColumnManager(false)
            ->columnManagerWidth(Width::Medium)
            ->columnManagerTriggerAction(
                fn (Action $action): Action => $action
                    ->tooltip('Столбцы')
                    ->extraAttributes(['class' => 'ac-table-toolbar-trigger'], merge: true),
            )
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Сценарии ещё не добавлены')
            ->emptyStateDescription('Создайте первый глобальный сценарий для будущего конструктора.')
            ->recordActionsColumnLabel('Кнопки')
            ->recordActions([
                Action::make('publishDraft')
                    ->icon(Heroicon::OutlinedBolt)
                    ->iconButton()
                    ->tooltip('Опубликовать черновик')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived && $record->draftVersion instanceof ScenarioVersion)
                    ->action(function (Scenario $record): void {
                        $draftVersion = $record->draftVersion()->first();

                        if (! $draftVersion instanceof ScenarioVersion) {
                            throw ValidationException::withMessages([
                                'scenario' => 'У сценария нет активного черновика для публикации.',
                            ]);
                        }

                        app(PublishScenarioVersionAction::class)->handle($draftVersion);

                        Notification::make()
                            ->success()
                            ->title('Черновик опубликован')
                            ->body('Опубликованная версия сценария обновлена.')
                            ->send();
                    }),
                Action::make('createNextDraft')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->iconButton()
                    ->tooltip('Создать новый черновик')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived && $record->draftVersion === null && $record->publishedVersion instanceof ScenarioVersion)
                    ->action(function (Scenario $record): void {
                        app(CreateNextScenarioDraftAction::class)->handle($record);

                        Notification::make()
                            ->success()
                            ->title('Черновик создан')
                            ->body('Создан новый черновик на основе опубликованной версии.')
                            ->send();
                    }),
                EditAction::make()
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->tooltip('Изменить сценарий')
                    ->modalWidth(Width::FiveExtraLarge)
                    ->modalFooterActionsAlignment(Alignment::End)
                    ->extraModalWindowAttributes(['class' => 'ac-scenario-form-modal'])
                    ->using(fn (array $data, Scenario $record): Scenario => static::saveScenario($data, $record)),
                Action::make('archiveScenario')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Архивировать сценарий')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived)
                    ->action(function (Scenario $record): void {
                        app(ArchiveScenarioAction::class)->handle($record);

                        Notification::make()
                            ->success()
                            ->title('Сценарий архивирован')
                            ->body('Сценарий переведён в архив и отключён.')
                            ->send();
                    }),
            ])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScenarios::route('/'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function saveScenario(array $data, ?Scenario $record = null): Scenario
    {
        if (! $record instanceof Scenario) {
            return app(CreateScenarioAction::class)->handle([
                'code' => (string) ($data['code'] ?? ''),
                'name' => (string) ($data['name'] ?? ''),
                'is_active' => (bool) ($data['is_active'] ?? true),
            ]);
        }

        return DB::transaction(function () use ($data, $record): Scenario {
            $record->fill([
                'name' => (string) ($data['name'] ?? $record->name),
                'is_active' => (bool) ($record->is_archived ? false : ($data['is_active'] ?? $record->is_active)),
            ])->save();

            $draftVersion = $record->draftVersion()->first();

            if (
                $draftVersion instanceof ScenarioVersion
                && array_key_exists('draft_schema_payload_json', $data)
            ) {
                $draftVersion->forceFill([
                    'schema_payload' => static::decodeSchemaPayload((string) $data['draft_schema_payload_json']),
                ])->save();
            }

            return $record->fresh(['draftVersion', 'publishedVersion', 'versions']);
        });
    }

    protected static function formatScenarioStatus(?Scenario $record): string
    {
        if (! $record instanceof Scenario) {
            return 'Новый сценарий';
        }

        if ((bool) $record->is_archived) {
            return 'Архивный';
        }

        return (bool) $record->is_active ? 'Активен' : 'Отключён';
    }

    protected static function formatVersionLabel(?ScenarioVersion $version): string
    {
        if (! $version instanceof ScenarioVersion) {
            return 'Нет';
        }

        return 'v'.$version->version_number;
    }

    protected static function formatVersionsSummary(Scenario $record): string
    {
        if ($record->versions->isEmpty()) {
            return 'Версий нет';
        }

        return $record->versions
            ->sortByDesc('version_number')
            ->map(fn (ScenarioVersion $version): string => sprintf(
                'v%d — %s',
                $version->version_number,
                static::translateVersionStatus($version->status),
            ))
            ->implode(', ');
    }

    protected static function buildVersionsOverview(?Scenario $record): HtmlString
    {
        if (! $record instanceof Scenario || $record->versions->isEmpty()) {
            return new HtmlString('Версий пока нет.');
        }

        $lines = $record->versions
            ->sortByDesc('version_number')
            ->map(fn (ScenarioVersion $version): string => e(sprintf(
                'v%d — %s',
                $version->version_number,
                static::translateVersionStatus($version->status),
            )))
            ->implode('<br>');

        return new HtmlString($lines);
    }

    protected static function translateVersionStatus(string $status): string
    {
        return match ($status) {
            ScenarioVersion::STATUS_DRAFT => 'Черновик',
            ScenarioVersion::STATUS_PUBLISHED => 'Опубликована',
            ScenarioVersion::STATUS_ARCHIVED => 'Архив',
            default => $status,
        };
    }

    /**
     * @param  array<mixed, mixed>  $schemaPayload
     */
    protected static function encodeSchemaPayload(array $schemaPayload): string
    {
        if ($schemaPayload === []) {
            return '{}';
        }

        $encoded = json_encode(
            $schemaPayload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return $encoded === false ? '{}' : $encoded;
    }

    /**
     * @return array<mixed, mixed>
     */
    protected static function decodeSchemaPayload(string $schemaPayloadJson): array
    {
        $trimmedPayload = trim($schemaPayloadJson);

        if ($trimmedPayload === '') {
            throw ValidationException::withMessages([
                'draft_schema_payload_json' => 'Нужно указать JSON для схемы черновика.',
            ]);
        }

        try {
            $decodedPayload = json_decode($trimmedPayload, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw ValidationException::withMessages([
                'draft_schema_payload_json' => 'Схема черновика должна быть валидным JSON.',
            ]);
        }

        if (! is_array($decodedPayload)) {
            throw ValidationException::withMessages([
                'draft_schema_payload_json' => 'Схема сценария должна быть JSON-объектом.',
            ]);
        }

        return $decodedPayload;
    }
}
