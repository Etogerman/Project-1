<?php

namespace App\Filament\Pages;

use App\Models\BotConstructorArrow;
use App\Models\BotConstructorArrowRun;
use App\Models\BotConstructorBlock;
use App\Models\BotConstructorConstant;
use App\Models\Channel;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UnitEnum;

class BotConstructor extends Page
{
    private const DEFAULT_TITLE = 'Стартовое условие';

    private const DEFAULT_X = 64;

    private const DEFAULT_Y = 64;

    private const NEW_BLOCK_OFFSET = 32;

    protected static ?string $slug = 'constructor';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Конструктор';

    protected static string|UnitEnum|null $navigationGroup = 'Интеграции';

    protected static ?int $navigationSort = 15;

    protected static ?string $title = 'Конструктор';

    protected string $view = 'filament.bot-constructor.pages.builder';

    protected Width|string|null $maxContentWidth = Width::Full;

    public ?int $selectedBlockId = null;

    public ?int $selectedArrowId = null;

    public string $draftTitle = self::DEFAULT_TITLE;

    public bool $draftIsActive = false;

    /**
     * @var list<int>
     */
    public array $draftChannelIds = [];

    public string $draftMatchType = BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD;

    public string $draftMatchValuesInput = '';

    public string $draftResponseText = '';

    public int $draftX = self::DEFAULT_X;

    public int $draftY = self::DEFAULT_Y;

    public int $draftArrowSourceBlockId = 0;

    public int $draftArrowTargetBlockId = 0;

    public bool $draftArrowIsActive = true;

    public int $draftArrowDelayValue = 0;

    public string $draftArrowDelayUnit = BotConstructorArrow::DELAY_UNIT_SECONDS;

    public bool $draftArrowCancelIfLeftSourceBlock = false;

    public string $draftArrowConditionMatchType = BotConstructorArrow::CONDITION_ALWAYS;

    public string $draftArrowConditionValue = '';

    public int $draftArrowPriority = 100;

    public string $draftArrowPassLimitMode = BotConstructorArrow::PASS_LIMIT_MODE_CONSTANT;

    public ?int $draftArrowPassLimitValue = null;

    public int $draftArrowPassLimitConstant = BotConstructorConstant::DEFAULT_ARROW_PASS_LIMIT;

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->draftArrowPassLimitConstant = $this->currentArrowPassLimitConstantValue()
            ?? BotConstructorConstant::DEFAULT_ARROW_PASS_LIMIT;

        $firstBlock = BotConstructorBlock::query()
            ->orderBy('id')
            ->first();

        if ($firstBlock instanceof BotConstructorBlock) {
            $this->loadBlock($firstBlock);
        }
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->canManageSystem();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Конструктор';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function addBlock(): void
    {
        abort_unless(static::canAccess(), 403);

        $existingCount = BotConstructorBlock::query()->count();
        $offset = min($existingCount * self::NEW_BLOCK_OFFSET, 640);

        $block = BotConstructorBlock::query()->create([
            'title' => self::DEFAULT_TITLE,
            'is_active' => false,
            'match_type' => BotConstructorBlock::MATCH_TYPE_EXACT_KEYWORD,
            'match_values' => [],
            'response_text' => null,
            'x' => self::DEFAULT_X + $offset,
            'y' => self::DEFAULT_Y + $offset,
        ]);

        $this->loadBlock($block);

        Notification::make()
            ->success()
            ->title('Стартовое условие добавлено')
            ->body('Блок создан выключенным. Заполните настройки и нажмите «Сохранить».')
            ->send();
    }

    public function selectBlock(int $blockId): void
    {
        abort_unless(static::canAccess(), 403);

        $this->loadBlock($this->findBlock($blockId));
    }

    public function selectArrow(int $arrowId): void
    {
        abort_unless(static::canAccess(), 403);

        $this->loadArrow($this->findArrow($arrowId));
    }

    public function moveBlock(int $blockId, int $x, int $y): void
    {
        abort_unless(static::canAccess(), 403);

        $block = $this->findBlock($blockId);
        $block->forceFill([
            'x' => max(0, min($x, 5000)),
            'y' => max(0, min($y, 5000)),
        ])->save();

        if ((int) $this->selectedBlockId === (int) $block->id) {
            $this->draftX = (int) $block->x;
            $this->draftY = (int) $block->y;
        }

        $this->skipRender();
    }

    public function addArrow(): void
    {
        abort_unless(static::canAccess(), 403);

        $sourceBlock = $this->selectedBlockId !== null
            ? BotConstructorBlock::query()->find($this->selectedBlockId)
            : BotConstructorBlock::query()->orderBy('id')->first();

        if (! $sourceBlock instanceof BotConstructorBlock) {
            Notification::make()
                ->danger()
                ->title('Нет исходного блока')
                ->body('Сначала создайте стартовое условие.')
                ->send();

            return;
        }

        $targetBlock = BotConstructorBlock::query()
            ->where('id', '!=', $sourceBlock->id)
            ->orderBy('id')
            ->first();

        if (! $targetBlock instanceof BotConstructorBlock) {
            $targetBlock = $sourceBlock;
        }

        $arrow = BotConstructorArrow::query()->create([
            'source_block_id' => $sourceBlock->id,
            'target_block_id' => $targetBlock->id,
            'is_active' => false,
            'delay_value' => 0,
            'delay_unit' => BotConstructorArrow::DELAY_UNIT_SECONDS,
            'cancel_if_left_source_block' => false,
            'condition_match_type' => BotConstructorArrow::CONDITION_ALWAYS,
            'condition_value' => null,
            'priority' => 100,
            'pass_limit_mode' => BotConstructorArrow::PASS_LIMIT_MODE_CONSTANT,
            'pass_limit_value' => null,
        ]);

        $this->loadArrow($arrow);

        Notification::make()
            ->success()
            ->title('Стрелка добавлена')
            ->body('Стрелка создана выключенной. Проверьте настройки, включите её и нажмите «Сохранить».')
            ->send();
    }

    public function saveSelectedElement(): void
    {
        if ($this->selectedArrowId !== null) {
            $this->saveArrow();

            return;
        }

        $this->saveBlock();
    }

    public function saveBlock(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->selectedBlockId === null) {
            throw ValidationException::withMessages([
                'selectedBlockId' => 'Сначала выберите блок.',
            ]);
        }

        $block = $this->findBlock($this->selectedBlockId);
        $condition = BotConstructorBlock::normalizeConditionInput(
            $this->draftMatchType,
            $this->draftMatchValuesInput,
        );
        $channelIds = $this->normalizeChannelIds($this->draftChannelIds);

        if ($this->draftIsActive) {
            $this->validateActiveBlock($condition['match_type'], $condition['match_values'], $channelIds);
        }

        $block->forceFill([
            'title' => filled($this->draftTitle) ? trim($this->draftTitle) : self::DEFAULT_TITLE,
            'is_active' => $this->draftIsActive,
            'match_type' => $condition['match_type'],
            'match_values' => $condition['match_values'],
            'response_text' => $this->draftResponseText,
            'x' => max(0, min($this->draftX, 5000)),
            'y' => max(0, min($this->draftY, 5000)),
        ])->save();

        $block->channels()->sync($channelIds);
        $this->loadBlock($block->fresh(['channels']));

        Notification::make()
            ->success()
            ->title('Блок сохранён')
            ->send();
    }

    public function saveArrow(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->selectedArrowId === null) {
            throw ValidationException::withMessages([
                'selectedArrowId' => 'Сначала выберите стрелку.',
            ]);
        }

        $arrow = $this->findArrow($this->selectedArrowId);
        $sourceBlockId = (int) $this->draftArrowSourceBlockId;
        $targetBlockId = (int) $this->draftArrowTargetBlockId;
        $conditionMatchType = trim($this->draftArrowConditionMatchType);
        $conditionValue = trim($this->draftArrowConditionValue);
        $passLimitMode = trim($this->draftArrowPassLimitMode);
        $passLimitValue = $passLimitMode === BotConstructorArrow::PASS_LIMIT_MODE_MANUAL
            ? (int) $this->draftArrowPassLimitValue
            : null;

        $this->validateArrowDraft($sourceBlockId, $targetBlockId, $conditionMatchType, $conditionValue, $passLimitMode, $passLimitValue);

        $arrow->forceFill([
            'source_block_id' => $sourceBlockId,
            'target_block_id' => $targetBlockId,
            'is_active' => $this->draftArrowIsActive,
            'delay_value' => (int) $this->draftArrowDelayValue,
            'delay_unit' => $this->draftArrowDelayUnit,
            'cancel_if_left_source_block' => $this->draftArrowCancelIfLeftSourceBlock,
            'condition_match_type' => $conditionMatchType,
            'condition_value' => $conditionMatchType === BotConstructorArrow::CONDITION_ALWAYS ? null : $conditionValue,
            'priority' => (int) $this->draftArrowPriority,
            'pass_limit_mode' => $passLimitMode,
            'pass_limit_value' => $passLimitValue,
        ])->save();

        $this->loadArrow($arrow->fresh(['sourceBlock', 'targetBlock']));

        Notification::make()
            ->success()
            ->title('Стрелка сохранена')
            ->send();
    }

    public function deleteArrow(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->selectedArrowId === null) {
            return;
        }

        $sourceBlockId = null;

        DB::transaction(function () use (&$sourceBlockId): void {
            $arrow = $this->findArrow($this->selectedArrowId);
            $sourceBlockId = (int) $arrow->source_block_id;

            $arrow->delete();

            BotConstructorArrowRun::query()
                ->where('bot_constructor_arrow_id', $arrow->id)
                ->where('status', BotConstructorArrowRun::STATUS_SCHEDULED)
                ->update([
                    'status' => BotConstructorArrowRun::STATUS_CANCELLED,
                    'error_message' => 'Стрелка удалена администратором.',
                    'updated_at' => now(),
                ]);
        });

        $fallbackBlock = $sourceBlockId === null
            ? BotConstructorBlock::query()->orderBy('id')->first()
            : BotConstructorBlock::query()->find($sourceBlockId);

        $this->loadBlock($fallbackBlock instanceof BotConstructorBlock
            ? $fallbackBlock
            : BotConstructorBlock::query()->orderBy('id')->first());

        Notification::make()
            ->success()
            ->title('Стрелка удалена')
            ->send();
    }

    public function deleteBlock(): void
    {
        abort_unless(static::canAccess(), 403);

        if ($this->selectedBlockId === null) {
            return;
        }

        $result = DB::transaction(function (): array {
            $block = BotConstructorBlock::withTrashed()
                ->whereKey($this->selectedBlockId)
                ->lockForUpdate()
                ->first();

            if (! $block instanceof BotConstructorBlock) {
                return [
                    'found' => false,
                    'already_deleted' => false,
                    'block_id' => (int) $this->selectedBlockId,
                    'deleted_arrow_count' => 0,
                    'cancelled_scheduled_run_count' => 0,
                    'cancelled_processing_run_count' => 0,
                ];
            }

            $alreadyDeleted = $block->trashed();

            $relatedArrowIds = BotConstructorArrow::withTrashed()
                ->where(function ($query) use ($block): void {
                    $query
                        ->where('source_block_id', $block->id)
                        ->orWhere('target_block_id', $block->id);
                })
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            $deletedArrowCount = $relatedArrowIds === []
                ? 0
                : BotConstructorArrow::query()
                    ->whereIn('id', $relatedArrowIds)
                    ->count();

            if (! $alreadyDeleted) {
                $block->forceFill(['is_active' => false])->save();
                $block->delete();
            }

            if ($relatedArrowIds !== []) {
                BotConstructorArrow::query()
                    ->whereIn('id', $relatedArrowIds)
                    ->delete();
            }

            $cancelledScheduledRunCount = $relatedArrowIds === []
                ? 0
                : BotConstructorArrowRun::query()
                    ->whereIn('bot_constructor_arrow_id', $relatedArrowIds)
                    ->where('status', BotConstructorArrowRun::STATUS_SCHEDULED)
                    ->update([
                        'status' => BotConstructorArrowRun::STATUS_CANCELLED,
                        'processing_started_at' => null,
                        'error_message' => 'Блок удалён администратором.',
                        'updated_at' => now(),
                    ]);

            $cancelledProcessingRunCount = $relatedArrowIds === []
                ? 0
                : BotConstructorArrowRun::query()
                    ->whereIn('bot_constructor_arrow_id', $relatedArrowIds)
                    ->where('status', BotConstructorArrowRun::STATUS_PROCESSING)
                    ->whereNotExists(function ($query): void {
                        $query
                            ->selectRaw('1')
                            ->from('bot_constructor_execution_block_runs')
                            ->whereColumn('bot_constructor_execution_block_runs.bot_constructor_arrow_run_id', 'bot_constructor_arrow_runs.id');
                    })
                    ->update([
                        'status' => BotConstructorArrowRun::STATUS_CANCELLED,
                        'processing_started_at' => null,
                        'error_message' => 'Блок удалён администратором.',
                        'updated_at' => now(),
                    ]);

            return [
                'found' => true,
                'already_deleted' => $alreadyDeleted,
                'block_id' => (int) $block->id,
                'deleted_arrow_count' => $deletedArrowCount,
                'cancelled_scheduled_run_count' => $cancelledScheduledRunCount,
                'cancelled_processing_run_count' => $cancelledProcessingRunCount,
            ];
        });

        if ($result['found'] === false) {
            $this->selectedBlockId = null;
            $this->selectedArrowId = null;

            Notification::make()
                ->danger()
                ->title('Блок не найден')
                ->send();

            return;
        }

        logger()->info('bot_constructor.block_deleted', [
            'user_id' => auth()->id(),
            'block_id' => $result['block_id'],
            'deleted_arrow_count' => $result['deleted_arrow_count'],
            'cancelled_scheduled_run_count' => $result['cancelled_scheduled_run_count'],
            'cancelled_processing_run_count' => $result['cancelled_processing_run_count'],
        ]);

        $this->selectedBlockId = null;
        $this->selectedArrowId = null;

        Notification::make()
            ->success()
            ->title($result['already_deleted'] ? 'Блок уже удалён' : 'Блок удалён')
            ->send();
    }

    public function saveArrowPassLimitConstant(): void
    {
        abort_unless(static::canAccess(), 403);

        $value = (int) $this->draftArrowPassLimitConstant;

        if ($value < 1) {
            throw ValidationException::withMessages([
                'draftArrowPassLimitConstant' => 'Лимит переходов должен быть больше 0.',
            ]);
        }

        BotConstructorConstant::query()->updateOrCreate(
            ['key' => BotConstructorConstant::KEY_ARROW_PASS_LIMIT],
            [
                'name' => 'Лимит переходов клиента по стрелке',
                'value_type' => BotConstructorConstant::VALUE_TYPE_INTEGER,
                'value' => (string) $value,
                'description' => 'Значение по умолчанию для стрелок конструктора в режиме константы.',
            ],
        );

        $this->draftArrowPassLimitConstant = $value;

        Notification::make()
            ->success()
            ->title('Константа сохранена')
            ->send();
    }

    /**
     * @return list<array{
     *     id:int,
     *     title:string,
     *     is_active:bool,
     *     match_type:string,
     *     match_label:string,
     *     condition_label:string,
     *     response_label:string,
     *     channel_label:string,
     *     is_selected:bool,
     *     position:array{x:int,y:int}
     * }>
     */
    public function blocks(): array
    {
        return BotConstructorBlock::query()
            ->with('channels')
            ->orderBy('id')
            ->get()
            ->map(function (BotConstructorBlock $block): array {
                return [
                    'id' => (int) $block->id,
                    'title' => $block->title,
                    'is_active' => (bool) $block->is_active,
                    'match_type' => $block->match_type,
                    'match_label' => BotConstructorBlock::matchTypeOptions()[$block->match_type] ?? $block->match_type,
                    'condition_label' => $this->conditionLabel($block),
                    'response_label' => BotConstructorBlock::isNoReply($block->response_text) ? 'без ответа' : 'есть ответ',
                    'channel_label' => $this->blockChannelLabel($block),
                    'is_selected' => (int) $block->id === (int) $this->selectedBlockId,
                    'position' => [
                        'x' => (int) $block->x,
                        'y' => (int) $block->y,
                    ],
                ];
            })
            ->all();
    }

    /**
     * @return list<array{
     *     id:int,
     *     source_block_id:int,
     *     target_block_id:int,
     *     is_active:bool,
     *     is_selected:bool,
     *     condition_label:string,
     *     delay_label:string,
     *     limit_label:string
     * }>
     */
    public function arrows(): array
    {
        return BotConstructorArrow::query()
            ->with(['sourceBlock', 'targetBlock'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (BotConstructorArrow $arrow): bool => $arrow->sourceBlock instanceof BotConstructorBlock
                && ! $arrow->sourceBlock->trashed()
                && $arrow->targetBlock instanceof BotConstructorBlock
                && ! $arrow->targetBlock->trashed())
            ->map(fn (BotConstructorArrow $arrow): array => [
                'id' => (int) $arrow->id,
                'source_block_id' => (int) $arrow->source_block_id,
                'target_block_id' => (int) $arrow->target_block_id,
                'is_active' => (bool) $arrow->is_active,
                'is_selected' => (int) $arrow->id === (int) $this->selectedArrowId,
                'condition_label' => $this->arrowConditionLabel($arrow),
                'delay_label' => $this->arrowDelayLabel($arrow),
                'limit_label' => $this->arrowLimitLabel($arrow),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function blockOptions(): array
    {
        return BotConstructorBlock::query()
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (BotConstructorBlock $block): array => [
                (int) $block->id => '#'.$block->id.' '.$block->title,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function matchTypeOptions(): array
    {
        return BotConstructorBlock::matchTypeOptions();
    }

    /**
     * @return array<string, string>
     */
    public function arrowConditionTypeOptions(): array
    {
        return [
            BotConstructorArrow::CONDITION_ALWAYS => 'Всегда',
            BotConstructorArrow::CONDITION_EXACT_TEXT => 'Полное совпадение текста',
            BotConstructorArrow::CONDITION_CONTAINS_TEXT => 'Содержит текст',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function arrowDelayUnitOptions(): array
    {
        return [
            BotConstructorArrow::DELAY_UNIT_SECONDS => 'Секунд',
            BotConstructorArrow::DELAY_UNIT_MINUTES => 'Минут',
            BotConstructorArrow::DELAY_UNIT_HOURS => 'Часов',
            BotConstructorArrow::DELAY_UNIT_DAYS => 'Дней',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function arrowPassLimitModeOptions(): array
    {
        return [
            BotConstructorArrow::PASS_LIMIT_MODE_CONSTANT => 'Константа',
            BotConstructorArrow::PASS_LIMIT_MODE_MANUAL => 'Ручное значение',
        ];
    }

    public function arrowPassLimitConstantLabel(): string
    {
        $value = $this->currentArrowPassLimitConstantValue();

        return $value === null ? 'константа не задана' : (string) $value;
    }

    /**
     * @return array<int, array{id:int,label:string,is_ready:bool,status:string}>
     */
    public function channelOptions(): array
    {
        return Channel::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Channel $channel): array => [
                'id' => (int) $channel->id,
                'label' => $this->channelLabel($channel),
                'is_ready' => $channel->isReadyForConstructorAutoReplies(),
                'status' => $channel->getHealthStatusLabel(),
            ])
            ->all();
    }

    public function hasSelectedBlock(): bool
    {
        return $this->selectedBlockId !== null
            && BotConstructorBlock::query()->whereKey($this->selectedBlockId)->exists();
    }

    public function hasSelectedArrow(): bool
    {
        return $this->selectedArrowId !== null
            && BotConstructorArrow::query()->whereKey($this->selectedArrowId)->exists();
    }

    public function hasSelectedElement(): bool
    {
        return $this->hasSelectedBlock() || $this->hasSelectedArrow();
    }

    public function canCreateArrow(): bool
    {
        return BotConstructorBlock::query()->exists();
    }

    private function currentArrowPassLimitConstantValue(): ?int
    {
        $constant = BotConstructorConstant::query()
            ->where('key', BotConstructorConstant::KEY_ARROW_PASS_LIMIT)
            ->first();

        return $constant?->integerValue();
    }

    private function loadBlock(?BotConstructorBlock $block): void
    {
        if (! $block instanceof BotConstructorBlock) {
            $this->selectedBlockId = null;
            $this->selectedArrowId = null;

            return;
        }

        $block->loadMissing('channels');

        $this->selectedBlockId = (int) $block->id;
        $this->selectedArrowId = null;
        $this->draftTitle = $block->title;
        $this->draftIsActive = (bool) $block->is_active;
        $this->draftChannelIds = $block->channels
            ->pluck('id')
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->values()
            ->all();
        $this->draftMatchType = $block->match_type;
        $this->draftMatchValuesInput = implode('; ', $block->match_values ?? []);
        $this->draftResponseText = (string) ($block->response_text ?? '');
        $this->draftX = (int) $block->x;
        $this->draftY = (int) $block->y;
    }

    private function loadArrow(?BotConstructorArrow $arrow): void
    {
        if (! $arrow instanceof BotConstructorArrow) {
            $this->selectedArrowId = null;

            return;
        }

        $this->selectedArrowId = (int) $arrow->id;
        $this->selectedBlockId = null;
        $this->draftArrowSourceBlockId = (int) $arrow->source_block_id;
        $this->draftArrowTargetBlockId = (int) $arrow->target_block_id;
        $this->draftArrowIsActive = (bool) $arrow->is_active;
        $this->draftArrowDelayValue = (int) $arrow->delay_value;
        $this->draftArrowDelayUnit = $arrow->delay_unit;
        $this->draftArrowCancelIfLeftSourceBlock = (bool) $arrow->cancel_if_left_source_block;
        $this->draftArrowConditionMatchType = $arrow->condition_match_type;
        $this->draftArrowConditionValue = (string) ($arrow->condition_value ?? '');
        $this->draftArrowPriority = (int) $arrow->priority;
        $this->draftArrowPassLimitMode = $arrow->pass_limit_mode;
        $this->draftArrowPassLimitValue = $arrow->pass_limit_value === null ? null : (int) $arrow->pass_limit_value;
    }

    private function findBlock(int $blockId): BotConstructorBlock
    {
        return BotConstructorBlock::query()->findOrFail($blockId);
    }

    private function findArrow(int $arrowId): BotConstructorArrow
    {
        return BotConstructorArrow::query()->findOrFail($arrowId);
    }

    /**
     * @param  list<string>  $matchValues
     * @param  list<int>  $channelIds
     */
    private function validateActiveBlock(string $matchType, array $matchValues, array $channelIds): void
    {
        $errors = [];

        if ($channelIds === []) {
            $errors['draftChannelIds'] = 'Выберите хотя бы один рабочий канал.';
        } else {
            $readyChannelCount = Channel::query()
                ->whereIn('id', $channelIds)
                ->get()
                ->filter(fn (Channel $channel): bool => $channel->isReadyForConstructorAutoReplies())
                ->count();

            if ($readyChannelCount !== count($channelIds)) {
                $errors['draftChannelIds'] = 'Один или несколько каналов сейчас не готовы к работе.';
            }
        }

        if ($matchType !== BotConstructorBlock::MATCH_TYPE_ANY_INBOUND && $matchValues === []) {
            $errors['draftMatchValuesInput'] = 'Укажите хотя бы одно значение условия.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function validateArrowDraft(
        int $sourceBlockId,
        int $targetBlockId,
        string $conditionMatchType,
        string $conditionValue,
        string $passLimitMode,
        ?int $passLimitValue,
    ): void {
        $errors = [];

        if (! BotConstructorBlock::query()->whereKey($sourceBlockId)->exists()) {
            $errors['draftArrowSourceBlockId'] = 'Выберите исходный блок.';
        }

        if (! BotConstructorBlock::query()->whereKey($targetBlockId)->exists()) {
            $errors['draftArrowTargetBlockId'] = 'Выберите целевой блок.';
        }

        if (! in_array($conditionMatchType, BotConstructorArrow::conditionTypes(), true)) {
            $errors['draftArrowConditionMatchType'] = 'Выберите тип условия стрелки.';
        }

        if ($conditionMatchType !== BotConstructorArrow::CONDITION_ALWAYS && $conditionValue === '') {
            $errors['draftArrowConditionValue'] = 'Укажите условие соединения.';
        }

        if (! in_array($this->draftArrowDelayUnit, BotConstructorArrow::delayUnits(), true)) {
            $errors['draftArrowDelayUnit'] = 'Выберите единицу задержки.';
        }

        if ((int) $this->draftArrowDelayValue < 0) {
            $errors['draftArrowDelayValue'] = 'Задержка не может быть отрицательной.';
        }

        if ($this->draftArrowDelayInSeconds() > 30 * 24 * 60 * 60) {
            $errors['draftArrowDelayValue'] = 'Задержка не может быть больше 30 дней.';
        }

        if (! in_array($passLimitMode, BotConstructorArrow::passLimitModes(), true)) {
            $errors['draftArrowPassLimitMode'] = 'Выберите режим лимита.';
        }

        if ($passLimitMode === BotConstructorArrow::PASS_LIMIT_MODE_MANUAL && (int) $passLimitValue < 1) {
            $errors['draftArrowPassLimitValue'] = 'Лимит переходов должен быть больше 0.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function draftArrowDelayInSeconds(): int
    {
        return match ($this->draftArrowDelayUnit) {
            BotConstructorArrow::DELAY_UNIT_MINUTES => (int) $this->draftArrowDelayValue * 60,
            BotConstructorArrow::DELAY_UNIT_HOURS => (int) $this->draftArrowDelayValue * 60 * 60,
            BotConstructorArrow::DELAY_UNIT_DAYS => (int) $this->draftArrowDelayValue * 24 * 60 * 60,
            default => (int) $this->draftArrowDelayValue,
        };
    }

    /**
     * @param  array<int, mixed>  $channelIds
     * @return list<int>
     */
    private function normalizeChannelIds(array $channelIds): array
    {
        return collect($channelIds)
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->filter(fn (int $channelId): bool => $channelId > 0)
            ->unique()
            ->values()
            ->all();
    }

    private function conditionLabel(BotConstructorBlock $block): string
    {
        if ($block->usesAnyInbound()) {
            return 'любое входящее';
        }

        $values = $block->match_values ?? [];

        if (! is_array($values) || $values === []) {
            return 'условие не задано';
        }

        return implode('; ', $values);
    }

    private function blockChannelLabel(BotConstructorBlock $block): string
    {
        $labels = $block->channels
            ->map(fn (Channel $channel): string => '#'.$channel->id.' '.$channel->name)
            ->values();

        return $labels->isEmpty() ? 'каналы не выбраны' : $labels->implode(', ');
    }

    private function arrowConditionLabel(BotConstructorArrow $arrow): string
    {
        return match ($arrow->condition_match_type) {
            BotConstructorArrow::CONDITION_EXACT_TEXT => 'текст = '.($arrow->condition_value ?: 'не задано'),
            BotConstructorArrow::CONDITION_CONTAINS_TEXT => 'содержит '.($arrow->condition_value ?: 'не задано'),
            default => 'всегда',
        };
    }

    private function arrowDelayLabel(BotConstructorArrow $arrow): string
    {
        if ($arrow->delayInSeconds() === 0) {
            return 'сразу';
        }

        $unit = $this->arrowDelayUnitOptions()[$arrow->delay_unit] ?? $arrow->delay_unit;

        return 'через '.$arrow->delay_value.' '.mb_strtolower($unit);
    }

    private function arrowLimitLabel(BotConstructorArrow $arrow): string
    {
        if ($arrow->pass_limit_mode === BotConstructorArrow::PASS_LIMIT_MODE_MANUAL) {
            return 'лимит '.$arrow->pass_limit_value;
        }

        return 'лимит '.$this->arrowPassLimitConstantLabel();
    }

    private function channelLabel(Channel $channel): string
    {
        $platform = Channel::platformOptions()[$channel->platform] ?? $channel->platform;

        return sprintf(
            '#%d %s (%s) · %s',
            $channel->id,
            $channel->name,
            $platform,
            $channel->getHealthStatusLabel(),
        );
    }
}
