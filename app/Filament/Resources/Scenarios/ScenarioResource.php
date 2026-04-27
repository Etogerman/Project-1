<?php

namespace App\Filament\Resources\Scenarios;

use App\Filament\Pages\ScenarioConstructor;
use App\Filament\Resources\Scenarios\Pages\ManageScenarios;
use App\Models\AutoReplyRule;
use App\Models\Scenario;
use App\Models\ScenarioVersion;
use App\Services\Scenarios\ArchiveScenarioAction;
use App\Services\Scenarios\CreateNextScenarioDraftAction;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use App\Services\Scenarios\RestoreScenarioAction;
use App\Services\Scenarios\ScenarioRegistry;
use App\Services\Scenarios\ValidateScenarioSchemaPayloadAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
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
    private const START_TRIGGER_TYPE_PARAMETER = 'parameter';

    private const DEFAULT_START_BLOCK_ID = 'welcome';

    private const DEFAULT_END_BLOCK_ID = 'done';

    private const DEFAULT_START_REPLY_TEXT = 'Старт сценария';

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
            'versions' => fn ($query) => $query->orderByDesc('version_number'),
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
                    ->description('Черновик можно настроить через визуальный стартовый блок или JSON fallback.')
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
                        Placeholder::make('draft_start_block_preview')
                            ->label('Старт сценария')
                            ->hidden(fn (?Scenario $record): bool => $record?->draftVersion === null
                                && $record?->publishedVersion === null)
                            ->columnSpanFull()
                            ->content(fn (?Scenario $record): HtmlString => static::buildStartBlockPreview($record)),
                        Placeholder::make('scenario_builder_entrypoint')
                            ->hiddenLabel()
                            ->visible(fn (?Scenario $record): bool => $record?->draftVersion instanceof ScenarioVersion)
                            ->columnSpanFull()
                            ->content(fn (?Scenario $record): HtmlString => static::buildScenarioBuilderEntrypoint($record)),
                        Placeholder::make('no_draft_state')
                            ->hiddenLabel()
                            ->visible(fn (?Scenario $record): bool => $record?->draftVersion === null)
                            ->content(new HtmlString(
                                '<strong>Активного черновика нет.</strong><br>'
                                .'Стартовое условие выше показано только для просмотра. Чтобы изменить старт сценария, закройте окно и нажмите действие «Создать новый черновик» в строке сценария.',
                            )),
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
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('name')
                    ->label('Название')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('published_version')
                    ->label('Опубликована')
                    ->state(fn (Scenario $record): string => static::formatVersionLabel($record->publishedVersion))
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->publishedVersion instanceof ScenarioVersion ? 'success' : 'gray')
                    ->toggleable(),
                TextColumn::make('draft_version')
                    ->label('Черновик')
                    ->state(fn (Scenario $record): string => static::formatVersionLabel($record->draftVersion))
                    ->badge()
                    ->color(fn (Scenario $record): string => $record->draftVersion instanceof ScenarioVersion ? 'warning' : 'gray')
                    ->toggleable(),
                TextColumn::make('versions_summary')
                    ->label('Версии')
                    ->state(fn (Scenario $record): string => static::formatVersionsSummary($record))
                    ->wrap()
                    ->limit(80)
                    ->tooltip(fn (Scenario $record): string => static::formatVersionsSummary($record))
                    ->toggleable(),
                TextColumn::make('is_active')
                    ->label('Активность')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Активен' : 'Отключён')
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('is_archived')
                    ->label('Архив')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Архивный' : 'Рабочий')
                    ->color(fn (bool $state): string => $state ? 'gray' : 'primary')
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('updated_at')
                    ->label('Обновлён')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
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
                Action::make('builder')
                    ->label('Открыть в конструкторе')
                    ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                    ->tooltip('Конструктор сценария')
                    ->color('success')
                    ->extraAttributes(['class' => 'ac-scenario-builder-table-action'])
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived
                        && $record->draftVersion instanceof ScenarioVersion
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->url(fn (Scenario $record): string => ScenarioConstructor::getUrl(['scenario' => $record->id])),
                Action::make('publishDraft')
                    ->icon(Heroicon::OutlinedBolt)
                    ->iconButton()
                    ->tooltip('Опубликовать черновик')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived
                        && $record->draftVersion instanceof ScenarioVersion
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Scenario $record): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

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
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived
                        && $record->draftVersion === null
                        && $record->publishedVersion instanceof ScenarioVersion
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->action(function (Scenario $record): void {
                        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

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
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived
                        && (auth()->user()?->can('update', $record) ?? false))
                    ->using(fn (array $data, Scenario $record): Scenario => static::saveScenario($data, $record)),
                Action::make('restoreScenario')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->iconButton()
                    ->tooltip('Восстановить сценарий')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => $record->is_archived
                        && (auth()->user()?->can('archive', $record) ?? false))
                    ->action(function (Scenario $record): void {
                        abort_unless(auth()->user()?->can('archive', $record) ?? false, 403);

                        app(RestoreScenarioAction::class)->handle($record);

                        Notification::make()
                            ->success()
                            ->title('Сценарий восстановлен')
                            ->body('Сценарий снова доступен для черновика и публикации, но остаётся выключенным.')
                            ->send();
                    }),
                Action::make('archiveScenario')
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->tooltip('Архивировать сценарий')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Scenario $record): bool => ! $record->is_archived
                        && (auth()->user()?->can('archive', $record) ?? false))
                    ->action(function (Scenario $record): void {
                        abort_unless(auth()->user()?->can('archive', $record) ?? false, 403);

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

        if ($record->is_archived) {
            throw ValidationException::withMessages([
                'scenario' => 'Архивный сценарий сначала нужно восстановить.',
            ]);
        }

        $scenario = DB::transaction(function () use ($data, $record): Scenario {
            $record->fill([
                'name' => (string) ($data['name'] ?? $record->name),
                'is_active' => (bool) ($record->is_archived ? false : ($data['is_active'] ?? $record->is_active)),
            ])->save();

            $draftVersion = $record->draftVersion()->first();

            if (
                $draftVersion instanceof ScenarioVersion
                && (
                    array_key_exists('draft_schema_payload_json', $data)
                    || static::hasStartBlockEditorData($data)
                )
            ) {
                if (
                    static::hasStartBlockEditorData($data)
                    && array_key_exists('draft_schema_payload_json', $data)
                    && static::hasSubmittedSchemaPayloadJsonChanged(
                        $draftVersion,
                        (string) $data['draft_schema_payload_json'],
                    )
                ) {
                    $schemaPayload = static::decodeSchemaPayload((string) $data['draft_schema_payload_json']);
                } elseif (static::hasStartBlockEditorData($data)) {
                    $schemaPayload = array_key_exists('draft_schema_payload_json', $data)
                        ? static::decodeSchemaPayloadJsonObject((string) $data['draft_schema_payload_json'])
                        : (is_array($draftVersion->schema_payload) ? $draftVersion->schema_payload : []);

                    $schemaPayload = static::applyStartBlockEditorData($schemaPayload, $data);
                } else {
                    $schemaPayload = static::decodeSchemaPayload((string) $data['draft_schema_payload_json']);
                }

                $draftVersion->forceFill([
                    'schema_payload' => $schemaPayload,
                ])->save();
            }

            return $record->fresh(['draftVersion', 'publishedVersion', 'versions']);
        });

        app(ScenarioRegistry::class)->forgetCachedDefinitions();

        return $scenario;
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

    protected static function buildStartBlockPreview(?Scenario $record): HtmlString
    {
        $schemaPayload = $record?->draftVersion?->schema_payload
            ?? $record?->publishedVersion?->schema_payload;
        $isReadonly = $record?->draftVersion === null;
        $modeSummary = $isReadonly
            ? 'Опубликованная версия показана только для просмотра. Создайте новый черновик, чтобы изменить старт.'
            : 'Активный черновик можно редактировать через отдельное действие «Конструктор».';

        if (! is_array($schemaPayload) || $schemaPayload === []) {
            return new HtmlString(
                '<div class="ac-scenario-start-preview">'
                .'<strong>Стартовое условие</strong>'
                .'<span>'.e($modeSummary).'</span>'
                .'<span>Черновик пока пустой. Добавьте trigger и выберите первый блок в конструкторе.</span>'
                .'</div>',
            );
        }

        $parameterTriggers = static::extractParameterTriggers($schemaPayload);
        $unsupportedTriggers = static::extractUnsupportedTriggers($schemaPayload);
        $startBlockId = is_string($schemaPayload['start_block_id'] ?? null)
            ? trim((string) $schemaPayload['start_block_id'])
            : 'не выбран';
        $triggerSummary = $parameterTriggers === []
            ? 'trigger-ы не настроены'
            : implode(', ', array_map(fn (array $trigger): string => e($trigger['value']), $parameterTriggers));
        $unsupportedSummary = $unsupportedTriggers === []
            ? ''
            : '<span class="ac-scenario-start-preview__warning">Есть trigger-ы вне Slice 1; визуальный редактор их не меняет.</span>';

        return new HtmlString(
            '<div class="ac-scenario-start-preview">'
            .'<strong>Стартовое условие</strong>'
            .'<span>'.e($modeSummary).'</span>'
            .'<span><b>Trigger-ы:</b> '.$triggerSummary.'</span>'
            .'<span><b>Первый блок:</b> '.e($startBlockId).'</span>'
            .$unsupportedSummary
            .'</div>',
        );
    }

    protected static function buildScenarioBuilderEntrypoint(?Scenario $record): HtmlString
    {
        if (! $record instanceof Scenario) {
            return new HtmlString('');
        }

        $builderUrl = e(ScenarioConstructor::getUrl(['scenario' => $record->id]));

        return new HtmlString(
            '<div class="ac-scenario-builder-entrypoint">'
            .'<div>'
            .'<strong>Визуальное редактирование вынесено в конструктор.</strong>'
            .'<span>Обычное окно меняет только карточку сценария. Стартовое условие, trigger-ы и JSON fallback редактируются на отдельном холсте.</span>'
            .'</div>'
            .'<a href="'.$builderUrl.'" class="ac-button ac-button--primary">Открыть конструктор</a>'
            .'</div>',
        );
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
        return app(ValidateScenarioSchemaPayloadAction::class)->handle(
            static::decodeSchemaPayloadJsonObject($schemaPayloadJson),
            'draft_schema_payload_json',
        );
    }

    /**
     * @return array<mixed, mixed>
     */
    protected static function decodeSchemaPayloadJsonObject(string $schemaPayloadJson): array
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

    /**
     * @param  array<string, mixed>  $data
     */
    protected static function hasStartBlockEditorData(array $data): bool
    {
        return array_key_exists('draft_start_triggers', $data)
            || array_key_exists('draft_start_condition_match', $data)
            || array_key_exists('draft_start_reply_text', $data)
            || array_key_exists('draft_start_block_id', $data);
    }

    protected static function hasSubmittedSchemaPayloadJsonChanged(ScenarioVersion $draftVersion, string $schemaPayloadJson): bool
    {
        $currentSchemaPayload = is_array($draftVersion->schema_payload)
            ? $draftVersion->schema_payload
            : [];

        return trim($schemaPayloadJson) !== trim(static::encodeSchemaPayload($currentSchemaPayload));
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function applyStartBlockEditorData(array $schemaPayload, array $data): array
    {
        if (static::extractUnsupportedTriggers($schemaPayload) !== []) {
            throw ValidationException::withMessages([
                'draft_start_triggers' => 'Визуальный старт пока поддерживает только trigger-ы типа message_parameter.',
            ]);
        }

        $startBlockId = static::normalizeStartBlockId($data['draft_start_block_id'] ?? null);
        $conditionMatch = static::normalizeStartConditionMatch($data['draft_start_condition_match'] ?? null);
        $replyText = static::normalizeStartReplyText(
            $data['draft_start_reply_text']
                ?? data_get($schemaPayload, "blocks.{$startBlockId}.text", self::DEFAULT_START_REPLY_TEXT),
        );
        $triggers = static::normalizeStartTriggerRows($data['draft_start_triggers'] ?? [], $conditionMatch);
        $schemaPayload = static::ensureStartEditorSchemaBase($schemaPayload, $startBlockId, $replyText);

        $blocks = $schemaPayload['blocks'] ?? null;

        if (! is_array($blocks) || array_is_list($blocks) || ! array_key_exists($startBlockId, $blocks)) {
            throw ValidationException::withMessages([
                'draft_start_block_id' => 'Первый блок сценария должен существовать.',
            ]);
        }

        if (! is_array($blocks[$startBlockId]) || ($blocks[$startBlockId]['type'] ?? null) !== 'message') {
            throw ValidationException::withMessages([
                'draft_start_reply_text' => 'Текст ответа можно сохранить только в message-блок старта.',
            ]);
        }

        $schemaPayload['version'] = (int) ($schemaPayload['version'] ?? 1);
        $schemaPayload['start_block_id'] = $startBlockId;
        $schemaPayload['triggers'] = $triggers;
        $schemaPayload['blocks'][$startBlockId]['text'] = $replyText;
        $schemaPayload['blocks'][$startBlockId]['text_format'] = $schemaPayload['blocks'][$startBlockId]['text_format'] ?? 'plain_text';

        return app(ValidateScenarioSchemaPayloadAction::class)->handle(
            $schemaPayload,
            'draft_start_triggers',
        );
    }

    /**
     * @return list<array{type: 'parameter', value?: string, match_scope?: string}>
     */
    protected static function normalizeStartTriggerRows(mixed $rows, string $conditionMatch): array
    {
        if ($conditionMatch === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return [[
                'type' => self::START_TRIGGER_TYPE_PARAMETER,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            ]];
        }

        if (! is_array($rows) || $rows === []) {
            throw ValidationException::withMessages([
                'draft_start_triggers' => 'Добавьте хотя бы один trigger запуска.',
            ]);
        }

        $triggers = [];
        $seenValues = [];

        foreach (array_values($rows) as $row) {
            $value = is_array($row) ? trim((string) ($row['value'] ?? '')) : '';

            if ($value === '') {
                throw ValidationException::withMessages([
                    'draft_start_triggers' => 'Значение trigger-а не может быть пустым.',
                ]);
            }

            $normalizedValue = AutoReplyRule::normalizeKeyword($value);

            if ($normalizedValue === null) {
                throw ValidationException::withMessages([
                    'draft_start_triggers' => 'Значение trigger-а не может быть пустым.',
                ]);
            }

            if (array_key_exists($normalizedValue, $seenValues)) {
                throw ValidationException::withMessages([
                    'draft_start_triggers' => 'Trigger-ы не должны повторяться.',
                ]);
            }

            $seenValues[$normalizedValue] = true;
            $trigger = [
                'type' => self::START_TRIGGER_TYPE_PARAMETER,
                'value' => $value,
            ];

            if ($conditionMatch !== AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER) {
                $trigger['match_scope'] = $conditionMatch;
            }

            $triggers[] = $trigger;
        }

        return $triggers;
    }

    protected static function normalizeStartConditionMatch(mixed $match): string
    {
        $normalizedMatch = is_string($match) ? trim($match) : '';

        return match ($normalizedMatch) {
            'exact' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'contains' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'starts_with', 'ends_with' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            default => array_key_exists($normalizedMatch, AutoReplyRule::matchScopeOptions())
                ? $normalizedMatch
                : AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
        };
    }

    protected static function normalizeStartReplyText(mixed $replyText): string
    {
        $normalizedReplyText = is_string($replyText) ? trim($replyText) : '';

        if ($normalizedReplyText === '') {
            throw ValidationException::withMessages([
                'draft_start_reply_text' => 'Введите текст ответа.',
            ]);
        }

        return $normalizedReplyText;
    }

    protected static function normalizeStartBlockId(mixed $value): string
    {
        $startBlockId = is_string($value) ? trim($value) : '';

        if ($startBlockId === '') {
            throw ValidationException::withMessages([
                'draft_start_block_id' => 'Выберите первый блок сценария.',
            ]);
        }

        return $startBlockId;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array<string, mixed>
     */
    protected static function ensureStartEditorSchemaBase(array $schemaPayload, string $startBlockId, string $replyText): array
    {
        if ($schemaPayload !== []) {
            return $schemaPayload;
        }

        return [
            'version' => 1,
            'start_block_id' => $startBlockId,
            'triggers' => [],
            'blocks' => [
                $startBlockId => [
                    'type' => 'message',
                    'text' => $replyText,
                    'next' => self::DEFAULT_END_BLOCK_ID,
                ],
                self::DEFAULT_END_BLOCK_ID => [
                    'type' => 'complete',
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return list<array{type: 'parameter', value: string}>
     */
    protected static function extractParameterTriggers(array $schemaPayload): array
    {
        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers)) {
            return [];
        }

        $parameterTriggers = [];

        foreach ($triggers as $trigger) {
            if (
                is_array($trigger)
                && ($trigger['type'] ?? null) === self::START_TRIGGER_TYPE_PARAMETER
                && is_string($trigger['value'] ?? null)
                && trim((string) $trigger['value']) !== ''
            ) {
                $parameterTriggers[] = [
                    'type' => self::START_TRIGGER_TYPE_PARAMETER,
                    'value' => trim((string) $trigger['value']),
                ];
            }
        }

        return $parameterTriggers;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return list<array<string, mixed>>
     */
    protected static function extractUnsupportedTriggers(array $schemaPayload): array
    {
        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers)) {
            return [];
        }

        return array_values(array_filter(
            $triggers,
            fn (mixed $trigger): bool => ! is_array($trigger)
                || ($trigger['type'] ?? null) !== self::START_TRIGGER_TYPE_PARAMETER,
        ));
    }

}
