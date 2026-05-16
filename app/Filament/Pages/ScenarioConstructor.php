<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Scenarios\ScenarioResource;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Scenario;
use App\Models\ScenarioBuilderBlock;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Scenarios\CreateNextScenarioDraftAction;
use App\Services\Scenarios\CreateScenarioAction;
use App\Services\Scenarios\PublishScenarioVersionAction;
use App\Services\Scenarios\ScenarioRegistry;
use App\Services\Scenarios\SyncScenarioBuilderStartBlockAction;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class ScenarioConstructor extends Page
{
    public const CONSTRUCTOR_WORKSPACE_CODE = Scenario::CONSTRUCTOR_WORKSPACE_CODE;

    private const START_TRIGGER_TYPE_PARAMETER = 'parameter';

    private const START_BUILDER_BLOCK_TYPE_LABEL = 'Стартовое условие';

    private const CONSTRUCTOR_WORKSPACE_NAME = 'Конструктор';

    private const DEFAULT_START_BLOCK_ID = 'welcome';

    private const DEFAULT_START_NODE_TITLE = 'Название блока';

    private const DEFAULT_START_REPLY_TEXT = 'Старт сценария';

    private const DEFAULT_START_NODE_POSITION = [
        'x' => 64,
        'y' => 64,
    ];

    protected static ?string $slug = 'scenario-constructor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Конструктор';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 16;

    protected static ?string $title = 'Конструктор';

    protected string $view = 'filament.scenarios.pages.scenario-builder';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $scenarioId = null;

    /**
     * @var list<array{value: string}>
     */
    public array $draftStartTriggers = [];

    public string $draftStartBlockId = self::DEFAULT_START_BLOCK_ID;

    public string $draftStartConditionMatch = AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;

    public string $draftStartReplyText = self::DEFAULT_START_REPLY_TEXT;

    /**
     * @var list<int>
     */
    public array $draftStartChannelIds = [];

    public string $draftStartNodeTitle = self::DEFAULT_START_NODE_TITLE;

    public ?int $draftStartBuilderBlockId = null;

    public ?int $selectedBuilderBlockId = null;

    public string $draftSchemaPayloadJson = '{}';

    /**
     * @var array{x: int, y: int}
     */
    public array $draftStartNodePosition = self::DEFAULT_START_NODE_POSITION;

    public bool $showJsonFallback = false;

    public function mount(?int $scenario = null): void
    {
        abort_unless(static::canAccess(), 403);

        $scenarioId = $scenario ?: (request()->integer('scenario') ?: null);

        $record = $scenarioId !== null
            ? $this->resolveScenarioRecord($scenarioId)
            : $this->resolveConstructorWorkspaceRecord();

        abort_unless($record instanceof Scenario, 404);
        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $this->scenarioId = (int) $record->id;
        $this->hydrateBuilderState();
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasRolePermission('scenarios.edit');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string|Htmlable
    {
        return 'Конструктор';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): string|Htmlable|null
    {
        return null;
    }

    public function getRecord(): Scenario
    {
        return $this->getScenarioRecord();
    }

    /**
     * @return array<string, string|int|null>
     */
    public function scenarioBuilderV3Config(): array
    {
        return [
            'scenarioId' => $this->scenarioId,
            'stateUrl' => $this->scenarioId !== null
                ? route('admin.scenario-constructor.v3.state.show', ['scenario' => $this->scenarioId])
                : null,
            'saveUrl' => $this->scenarioId !== null
                ? route('admin.scenario-constructor.v3.state.update', ['scenario' => $this->scenarioId])
                : null,
            'csrfToken' => csrf_token(),
        ];
    }

    public function addStartBuilderBlock(): void
    {
        $record = $this->getScenarioRecord();

        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $draftVersion = $record->draftVersion()->firstOrFail();
        $block = $this->syncScenarioBuilderStartBlockAction()->createStartBlock($draftVersion);

        $this->selectedBuilderBlockId = (int) $block->id;
        $this->hydrateBuilderState();

        Notification::make()
            ->success()
            ->title('Стартовое условие добавлено')
            ->body('Блок появился на холсте и доступен в панели настроек.')
            ->send();
    }

    public function selectStartBuilderBlock(int $blockId): void
    {
        $record = $this->getScenarioRecord();

        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $draftVersion = $record->draftVersion()->firstOrFail();
        $block = $this->syncScenarioBuilderStartBlockAction()->findStartBlock($draftVersion, $blockId);

        $this->loadStartBlockState($block);
    }

    public function deleteSelectedStartBuilderBlock(): void
    {
        $record = $this->getScenarioRecord();

        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        if ($this->selectedBuilderBlockId === null) {
            return;
        }

        $draftVersion = $record->draftVersion()->firstOrFail();

        $this->syncScenarioBuilderStartBlockAction()->deleteStartBlock($draftVersion, $this->selectedBuilderBlockId);

        $this->selectedBuilderBlockId = null;
        $this->hydrateBuilderState();

        Notification::make()
            ->success()
            ->title('Стартовое условие удалено')
            ->body('Блок удалён из локального конструктора.')
            ->send();
    }

    public function moveStartBuilderBlock(int $blockId, int $x, int $y): void
    {
        $record = $this->getScenarioRecord();

        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $draftVersion = $record->draftVersion()->firstOrFail();
        $block = $this->syncScenarioBuilderStartBlockAction()->moveStartBlock($draftVersion, $blockId, [
            'x' => $x,
            'y' => $y,
        ]);

        if ((int) $this->selectedBuilderBlockId === (int) $block->id) {
            $this->draftStartNodePosition = $this->syncScenarioBuilderStartBlockAction()->position($block);
        }
    }

    public function addDraftStartTrigger(): void
    {
        $this->draftStartTriggers[] = ['value' => ''];
    }

    public function removeDraftStartTrigger(int $index): void
    {
        if (! array_key_exists($index, $this->draftStartTriggers)) {
            return;
        }

        unset($this->draftStartTriggers[$index]);
        $this->draftStartTriggers = array_values($this->draftStartTriggers);

        if ($this->draftStartTriggers === []) {
            $this->draftStartTriggers = [['value' => '']];
        }
    }

    public function saveDraft(): void
    {
        $record = $this->getScenarioRecord();

        abort_unless(auth()->user()?->can('update', $record) ?? false, 403);

        $this->guardCanManageSelectedChannels();

        [$selectedBlockId, $selectedBlockIsPrimary] = DB::transaction(function () use ($record): array {
            $draftVersion = $record->draftVersion()->firstOrFail();
            $selectedBlockId = $this->selectedBuilderBlockId
                ?? (int) $this->syncScenarioBuilderStartBlockAction()->ensureStartBlock($draftVersion)->id;
            $selectedBlockIsPrimary = $this->syncScenarioBuilderStartBlockAction()
                ->isPrimaryStartBlock($draftVersion, $selectedBlockId);

            if ($selectedBlockIsPrimary || $this->showJsonFallback) {
                $scenarioData = [
                    'name' => $record->name,
                    'is_active' => $record->is_active,
                ];

                if ($selectedBlockIsPrimary) {
                    $scenarioData['draft_start_triggers'] = $this->draftStartTriggers;
                    $scenarioData['draft_start_condition_match'] = $this->draftStartConditionMatch;
                    $scenarioData['draft_start_reply_text'] = $this->draftStartReplyText;
                    $scenarioData['draft_start_block_id'] = $this->draftStartBlockId;
                }

                if ($this->showJsonFallback) {
                    $scenarioData['draft_schema_payload_json'] = $this->draftSchemaPayloadJson;
                }

                $scenario = ScenarioResource::saveScenario($scenarioData, $record);

                $this->scenarioId = (int) $scenario->id;
                $draftVersion = $this->getScenarioRecord()->draftVersion()->firstOrFail();
            }

            $this->syncScenarioBuilderStartBlockAction()->saveStartBlock(
                $draftVersion,
                $this->draftStartNodeTitle,
                $this->draftStartChannelIds,
                $this->draftStartNodePosition,
                $this->draftStartTriggers,
                $this->draftStartConditionMatch,
                $this->draftStartReplyText,
                $this->draftStartBlockId,
                $selectedBlockId,
            );

            return [$selectedBlockId, $selectedBlockIsPrimary];
        });

        $record = $this->getScenarioRecord();

        if ($this->isConstructorWorkspace($record)) {
            DB::transaction(function () use ($record): void {
                if ((bool) $record->is_archived || ! (bool) $record->is_active) {
                    $record->forceFill([
                        'name' => self::CONSTRUCTOR_WORKSPACE_NAME,
                        'is_active' => true,
                        'is_archived' => false,
                    ])->save();

                    app(ScenarioRegistry::class)->forgetCachedDefinitions();
                }

                $publishedVersion = app(PublishScenarioVersionAction::class)
                    ->handle($record->draftVersion()->firstOrFail());

                $this->syncConstructorWorkspaceBindings($publishedVersion);
                $record->refresh();
                app(CreateNextScenarioDraftAction::class)->handle($record);
            });

            $selectedBlockId = null;
        }

        $this->selectedBuilderBlockId = $selectedBlockId;
        $this->hydrateBuilderState();

        Notification::make()
            ->success()
            ->title($this->isConstructorWorkspace($this->getScenarioRecord()) ? 'Конструктор сохранён' : 'Черновик сохранён')
            ->body($this->isConstructorWorkspace($this->getScenarioRecord())
                ? 'Стартовые условия применены для выбранных каналов.'
                : ($selectedBlockIsPrimary
                    ? 'Основное стартовое условие и runtime-старт обновлены.'
                    : 'Стартовое условие сохранено в локальном конструкторе.'))
            ->send();
    }

    public function hasDraftVersion(): bool
    {
        return $this->getScenarioRecord()->draftVersion instanceof ScenarioVersion;
    }

    /**
     * @return list<array{
     *     id: int,
     *     title: string,
     *     channel_label: string,
     *     position: array{x: int, y: int},
     *     trigger_count: int,
     *     is_primary: bool,
     *     is_selected: bool
     * }>
     */
    public function builderStartBlocks(): array
    {
        $draftVersion = $this->getScenarioRecord()->draftVersion;

        if (! $draftVersion instanceof ScenarioVersion) {
            return [];
        }

        $startBlocks = $this->syncScenarioBuilderStartBlockAction()->startBlocks($draftVersion);
        $primaryBlockId = (int) ($startBlocks->first()?->id ?? 0);

        return $startBlocks
            ->map(fn (ScenarioBuilderBlock $block): array => [
                'id' => (int) $block->id,
                'title' => $this->normalizeStartNodeTitle($block->title),
                'channel_label' => $this->formatChannelLabels($block->channels),
                'position' => $this->syncScenarioBuilderStartBlockAction()->position($block),
                'trigger_count' => $block->conditions->count(),
                'is_primary' => (int) $block->id === $primaryBlockId,
                'is_selected' => (int) $block->id === (int) $this->selectedBuilderBlockId,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function startBlockOptions(): array
    {
        $schemaPayload = $this->getDraftSchemaPayload();
        $blocks = $schemaPayload['blocks'] ?? null;

        if (! is_array($blocks) || array_is_list($blocks) || $blocks === []) {
            return [
                self::DEFAULT_START_BLOCK_ID => 'welcome — технический стартовый блок',
            ];
        }

        $options = [];

        foreach ($blocks as $blockId => $block) {
            $normalizedBlockId = is_string($blockId) ? trim($blockId) : '';

            if ($normalizedBlockId === '' || $this->isGeneratedRuntimeStartBlockId($normalizedBlockId)) {
                continue;
            }

            $options[$normalizedBlockId] = $this->formatBlockOptionLabel($normalizedBlockId, is_array($block) ? $block : []);
        }

        return $options === []
            ? [self::DEFAULT_START_BLOCK_ID => 'welcome — технический стартовый блок']
            : $options;
    }

    /**
     * @return list<string>
     */
    public function triggerValues(): array
    {
        return array_values(array_filter(array_map(
            fn (array $trigger): string => trim((string) ($trigger['value'] ?? '')),
            $this->draftStartTriggers,
        )));
    }

    public function selectedStartBlockSummary(): string
    {
        $schemaPayload = $this->getDraftSchemaPayload();
        $blocks = $schemaPayload['blocks'] ?? [];
        $block = is_array($blocks) && is_array($blocks[$this->draftStartBlockId] ?? null)
            ? $blocks[$this->draftStartBlockId]
            : [];

        return $this->formatBlockOptionLabel($this->draftStartBlockId, $block);
    }

    public function startNodeTitle(): string
    {
        return $this->normalizeStartNodeTitle($this->draftStartNodeTitle);
    }

    public function startBuilderBlockId(): string
    {
        return $this->draftStartBuilderBlockId !== null ? (string) $this->draftStartBuilderBlockId : '—';
    }

    public function startBuilderBlockType(): string
    {
        return ScenarioBuilderBlock::TYPE_START_CONDITION;
    }

    public function startBuilderBlockTypeLabel(): string
    {
        return self::START_BUILDER_BLOCK_TYPE_LABEL;
    }

    /**
     * @return array<int, string>
     */
    public function channelOptions(): array
    {
        return Channel::query()
            ->whereKey($this->manageableConstructorChannelIds())
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Channel $channel): array => [
                (int) $channel->id => $this->formatChannelLabel($channel),
            ])
            ->all();
    }

    public function startBuilderConditionMatch(): string
    {
        return $this->normalizeStartConditionMatch($this->draftStartConditionMatch);
    }

    public function startBuilderConditionMatchLabel(): string
    {
        return $this->startBuilderConditionMatchOptions()[$this->startBuilderConditionMatch()]
            ?? $this->startBuilderConditionMatchOptions()[AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD];
    }

    /**
     * @return array<string, string>
     */
    public function startBuilderConditionMatchOptions(): array
    {
        return AutoReplyRule::matchScopeOptions();
    }

    public function startBuilderUsesKeywordScope(): bool
    {
        return $this->startBuilderConditionMatch() !== AutoReplyRule::MATCH_SCOPE_ANY_INBOUND;
    }

    public function startBuilderKeywordFieldLabel(): string
    {
        return match ($this->startBuilderConditionMatch()) {
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => 'Параметр для срабатывания',
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => 'Текст или параметр для срабатывания',
            default => 'Текст для срабатывания',
        };
    }

    public function selectedStartBuilderBlockIsPrimary(): bool
    {
        $draftVersion = $this->getScenarioRecord()->draftVersion;

        return $draftVersion instanceof ScenarioVersion
            && $this->selectedBuilderBlockId !== null
            && $this->syncScenarioBuilderStartBlockAction()->isPrimaryStartBlock($draftVersion, $this->selectedBuilderBlockId);
    }

    protected function hydrateBuilderState(): void
    {
        $draftVersion = $this->getScenarioRecord()->draftVersion;

        if ($draftVersion instanceof ScenarioVersion) {
            $block = $this->selectedBuilderBlockId !== null
                ? $draftVersion->builderBlocks()
                    ->whereKey($this->selectedBuilderBlockId)
                    ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
                    ->with(['channels', 'conditions', 'outgoingEdges'])
                    ->first()
                : null;

            if (! $block instanceof ScenarioBuilderBlock) {
                $block = $this->syncScenarioBuilderStartBlockAction()->firstStartBlock($draftVersion);
            }

            if ($block instanceof ScenarioBuilderBlock) {
                $this->loadStartBlockState($block);
            } else {
                $this->loadSchemaStartState($this->getDraftSchemaPayload());
            }

            $this->draftSchemaPayloadJson = $this->encodeSchemaPayload(
                $this->syncScenarioBuilderStartBlockAction()->schemaPayloadWithBuilderProjection($draftVersion),
            );

            return;
        }

        $schemaPayload = $this->getDraftSchemaPayload();

        $this->loadSchemaStartState($schemaPayload);
        $this->draftSchemaPayloadJson = $this->encodeSchemaPayload($schemaPayload);
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function loadSchemaStartState(array $schemaPayload): void
    {
        $this->draftStartBuilderBlockId = null;
        $this->selectedBuilderBlockId = null;
        $this->draftStartTriggers = $this->extractParameterTriggerRows($schemaPayload);
        $this->draftStartConditionMatch = $this->extractStartConditionMatch($schemaPayload);
        $this->draftStartReplyText = $this->extractStartReplyText($schemaPayload);
        $this->draftStartChannelIds = [];
        $this->draftStartBlockId = $this->extractStartBlockId($schemaPayload);
        $this->draftStartNodeTitle = $this->extractStartNodeTitle($schemaPayload);
        $this->draftStartNodePosition = $this->extractStartNodePosition($schemaPayload);
    }

    protected function loadStartBlockState(ScenarioBuilderBlock $block): void
    {
        $this->draftStartBuilderBlockId = (int) $block->id;
        $this->selectedBuilderBlockId = (int) $block->id;
        $this->draftStartTriggers = $this->syncScenarioBuilderStartBlockAction()->triggerRows($block);
        $this->draftStartConditionMatch = $this->syncScenarioBuilderStartBlockAction()->conditionMatch($block);
        $this->draftStartReplyText = $this->syncScenarioBuilderStartBlockAction()->replyText($block);
        $this->draftStartChannelIds = $this->syncScenarioBuilderStartBlockAction()->channelIds($block);
        $this->draftStartBlockId = $this->syncScenarioBuilderStartBlockAction()->startBlockId($block);
        $this->draftStartNodeTitle = $this->normalizeStartNodeTitle($block->title);
        $this->draftStartNodePosition = $this->syncScenarioBuilderStartBlockAction()->position($block);
    }

    protected function getScenarioRecord(): Scenario
    {
        /** @var Scenario $scenario */
        $scenario = Scenario::query()
            ->with(['draftVersion', 'publishedVersion', 'versions'])
            ->findOrFail($this->scenarioId);

        return $scenario;
    }

    protected function resolveConstructorWorkspaceRecord(): Scenario
    {
        return DB::transaction(function (): Scenario {
            $existingScenario = Scenario::query()
                ->where('code', self::CONSTRUCTOR_WORKSPACE_CODE)
                ->lockForUpdate()
                ->first();

            if (! $existingScenario instanceof Scenario) {
                return app(CreateScenarioAction::class)->handle([
                    'code' => self::CONSTRUCTOR_WORKSPACE_CODE,
                    'name' => self::CONSTRUCTOR_WORKSPACE_NAME,
                    'is_active' => true,
                ]);
            }

            if (! (bool) $existingScenario->is_archived && ! $existingScenario->draftVersion()->exists()) {
                $lastVersionNumber = (int) $existingScenario->versions()->max('version_number');

                ScenarioVersion::query()->create([
                    'scenario_id' => $existingScenario->id,
                    'version_number' => $lastVersionNumber + 1,
                    'status' => ScenarioVersion::STATUS_DRAFT,
                    'schema_payload' => [],
                ]);

                app(ScenarioRegistry::class)->forgetCachedDefinitions();
            }

            return $existingScenario->fresh(['draftVersion', 'publishedVersion', 'versions']);
        });
    }

    protected function resolveScenarioRecord(?int $scenarioId): ?Scenario
    {
        $query = Scenario::query()
            ->with(['draftVersion', 'publishedVersion', 'versions'])
            ->where('is_archived', false);

        if ($scenarioId !== null) {
            return (clone $query)->whereKey($scenarioId)->first();
        }

        return null;
    }

    protected function isGeneratedRuntimeStartBlockId(string $blockId): bool
    {
        return str_starts_with($blockId, 'builder_start_');
    }

    protected function isConstructorWorkspace(Scenario $scenario): bool
    {
        return (string) $scenario->code === self::CONSTRUCTOR_WORKSPACE_CODE;
    }

    protected function syncConstructorWorkspaceBindings(ScenarioVersion $publishedVersion): void
    {
        $channelIds = $publishedVersion->builderBlocks()
            ->where('type', ScenarioBuilderBlock::TYPE_START_CONDITION)
            ->with('channels:id')
            ->get()
            ->flatMap(fn (ScenarioBuilderBlock $block): array => $block->channels
                ->pluck('id')
                ->map(fn (mixed $channelId): int => (int) $channelId)
                ->all())
            ->unique()
            ->values()
            ->all();

        $this->guardCanManageChannelIds($channelIds);

        $manageableChannelIds = $this->manageableConstructorChannelIds();

        if ($manageableChannelIds !== []) {
            ScenarioChannelBinding::query()
                ->where('scenario_code', self::CONSTRUCTOR_WORKSPACE_CODE)
                ->whereIn('channel_id', $manageableChannelIds)
                ->when($channelIds !== [], fn ($query) => $query->whereNotIn('channel_id', $channelIds))
                ->update([
                    'is_active' => false,
                ]);
        }

        foreach ($channelIds as $channelId) {
            ScenarioChannelBinding::query()->updateOrCreate(
                [
                    'channel_id' => $channelId,
                    'scenario_code' => self::CONSTRUCTOR_WORKSPACE_CODE,
                ],
                [
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return list<int>
     */
    protected function manageableConstructorChannelIds(): array
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return [];
        }

        return Channel::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (Channel $channel): bool => $user->can('update', $channel))
            ->pluck('id')
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->all();
    }

    protected function guardCanManageSelectedChannels(): void
    {
        $this->guardCanManageChannelIds($this->draftStartChannelIds);
    }

    /**
     * @param  array<int, mixed>  $channelIds
     */
    protected function guardCanManageChannelIds(array $channelIds): void
    {
        $normalizedChannelIds = collect($channelIds)
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->filter(fn (int $channelId): bool => $channelId > 0)
            ->unique()
            ->values();

        if ($normalizedChannelIds->isEmpty()) {
            return;
        }

        $channels = Channel::query()
            ->whereKey($normalizedChannelIds->all())
            ->get();

        if ($channels->count() !== $normalizedChannelIds->count()) {
            throw ValidationException::withMessages([
                'draftStartChannelIds' => 'Выбранный канал не найден.',
            ]);
        }

        if ($channels->contains(fn (Channel $channel): bool => ! (bool) $channel->is_active)) {
            throw ValidationException::withMessages([
                'draftStartChannelIds' => 'Выбранный канал недоступен.',
            ]);
        }

        $user = auth()->user();

        if (! $user instanceof User || $channels->contains(fn (Channel $channel): bool => ! $user->can('update', $channel))) {
            throw ValidationException::withMessages([
                'draftStartChannelIds' => 'Недостаточно прав для настройки выбранных каналов.',
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getDraftSchemaPayload(): array
    {
        $schemaPayload = $this->getScenarioRecord()->draftVersion?->schema_payload;

        return is_array($schemaPayload) ? $schemaPayload : [];
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return list<array{value: string}>
     */
    protected function extractParameterTriggerRows(array $schemaPayload): array
    {
        $builderTriggerValues = data_get(
            $this->legacyStartBuilderBlock($schemaPayload),
            'settings.condition.values',
        );

        if (is_array($builderTriggerValues) && array_is_list($builderTriggerValues)) {
            $parameterTriggers = [];

            foreach ($builderTriggerValues as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $parameterTriggers[] = [
                        'value' => trim($value),
                    ];
                }
            }

            if ($parameterTriggers !== []) {
                return $parameterTriggers;
            }
        }

        $triggers = $schemaPayload['triggers'] ?? null;

        if (! is_array($triggers)) {
            return [['value' => '']];
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
                    'value' => trim((string) $trigger['value']),
                ];
            }
        }

        return $parameterTriggers === [] ? [['value' => '']] : $parameterTriggers;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function extractStartConditionMatch(array $schemaPayload): string
    {
        $builderConditionMatch = data_get($this->legacyStartBuilderBlock($schemaPayload), 'settings.condition.match');

        if (is_string($builderConditionMatch) && trim($builderConditionMatch) !== '') {
            return $this->normalizeStartConditionMatch($builderConditionMatch);
        }

        $triggers = $schemaPayload['triggers'] ?? null;

        if (is_array($triggers)) {
            foreach ($triggers as $trigger) {
                if (is_array($trigger) && is_string($trigger['match_scope'] ?? null)) {
                    return $this->normalizeStartConditionMatch($trigger['match_scope']);
                }

                if (is_array($trigger) && is_string($trigger['match'] ?? null)) {
                    return $this->normalizeStartConditionMatch($trigger['match']);
                }
            }

            if ($triggers !== []) {
                return AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER;
            }
        }

        return AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function extractStartReplyText(array $schemaPayload): string
    {
        $legacyBlock = $this->legacyStartBuilderBlock($schemaPayload);
        $builderReplyText = data_get($legacyBlock, 'settings.message_text', data_get($legacyBlock, 'message_text'));

        if (is_string($builderReplyText) && trim($builderReplyText) !== '') {
            return $this->normalizeStartReplyText($builderReplyText);
        }

        $startBlockId = $this->extractStartBlockId($schemaPayload);

        return $this->normalizeStartReplyText(data_get($schemaPayload, "blocks.{$startBlockId}.text"));
    }

    protected function normalizeStartConditionMatch(mixed $match): string
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

    protected function normalizeStartReplyText(mixed $replyText): string
    {
        $normalizedReplyText = is_string($replyText) ? trim($replyText) : '';

        return $normalizedReplyText !== '' ? $normalizedReplyText : self::DEFAULT_START_REPLY_TEXT;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function extractStartBlockId(array $schemaPayload): string
    {
        $builderStartBlockId = data_get($this->legacyStartBuilderBlock($schemaPayload), 'settings.start_block_id');

        if (is_string($builderStartBlockId) && trim($builderStartBlockId) !== '') {
            return trim($builderStartBlockId);
        }

        $startBlockId = $schemaPayload['start_block_id'] ?? null;

        return is_string($startBlockId) && trim($startBlockId) !== ''
            ? trim($startBlockId)
            : self::DEFAULT_START_BLOCK_ID;
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function extractStartNodeTitle(array $schemaPayload): string
    {
        return $this->normalizeStartNodeTitle(
            data_get($this->legacyStartBuilderBlock($schemaPayload), 'title', data_get($schemaPayload, 'ui.builder.nodes.start.title')),
        );
    }

    protected function normalizeStartNodeTitle(mixed $title): string
    {
        $normalizedTitle = is_string($title) ? trim($title) : '';

        if ($normalizedTitle === '') {
            return self::DEFAULT_START_NODE_TITLE;
        }

        return mb_substr($normalizedTitle, 0, 80);
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array{x: int, y: int}
     */
    protected function extractStartNodePosition(array $schemaPayload): array
    {
        $position = data_get(
            $this->legacyStartBuilderBlock($schemaPayload),
            'position',
            data_get($schemaPayload, 'ui.builder.nodes.start.position'),
        );

        return $this->normalizeStartNodePosition($position);
    }

    /**
     * @return array{x: int, y: int}
     */
    protected function normalizeStartNodePosition(mixed $position): array
    {
        if (! is_array($position)) {
            return self::DEFAULT_START_NODE_POSITION;
        }

        $x = is_numeric($position['x'] ?? null)
            ? (int) round((float) $position['x'])
            : self::DEFAULT_START_NODE_POSITION['x'];
        $y = is_numeric($position['y'] ?? null)
            ? (int) round((float) $position['y'])
            : self::DEFAULT_START_NODE_POSITION['y'];

        return [
            'x' => min(max($x, 24), 1400),
            'y' => min(max($y, 24), 1000),
        ];
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     */
    protected function encodeSchemaPayload(array $schemaPayload): string
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
     * @param  array<string, mixed>  $block
     */
    protected function formatBlockOptionLabel(string $blockId, array $block): string
    {
        $type = is_string($block['type'] ?? null) ? (string) $block['type'] : 'block';
        $text = is_string($block['text'] ?? null) ? trim((string) $block['text']) : '';

        if ($text !== '') {
            return sprintf('%s — %s — %s', $blockId, $type, str($text)->limit(48));
        }

        return sprintf('%s — %s', $blockId, $type);
    }

    protected function formatChannelLabel(?Channel $channel): string
    {
        if (! $channel instanceof Channel) {
            return 'Канал не выбран';
        }

        $platform = Channel::platformOptions()[$channel->platform] ?? $channel->platform;

        return sprintf('#%d %s (%s)', $channel->id, $channel->name, $platform);
    }

    /**
     * @param  Collection<int, Channel>  $channels
     */
    protected function formatChannelLabels(Collection $channels): string
    {
        if ($channels->isEmpty()) {
            return 'Каналы не выбраны';
        }

        return $channels
            ->map(fn (Channel $channel): string => $this->formatChannelLabel($channel))
            ->implode(', ');
    }

    /**
     * @param  array<string, mixed>  $schemaPayload
     * @return array<string, mixed>
     */
    protected function legacyStartBuilderBlock(array $schemaPayload): array
    {
        $blocks = data_get($schemaPayload, 'builder_schema.blocks');

        if (is_array($blocks)) {
            foreach ($blocks as $block) {
                if (
                    is_array($block)
                    && ($block['type'] ?? null) === ScenarioBuilderBlock::TYPE_START_CONDITION
                ) {
                    return $block;
                }
            }
        }

        $legacyBlock = data_get($schemaPayload, 'builder_schema.blocks.start');

        return is_array($legacyBlock) ? $legacyBlock : [];
    }

    protected function syncScenarioBuilderStartBlockAction(): SyncScenarioBuilderStartBlockAction
    {
        return app(SyncScenarioBuilderStartBlockAction::class);
    }
}
