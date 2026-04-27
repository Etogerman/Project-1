<?php

namespace App\Filament\Pages;

use App\Models\BotConstructorBlock;
use App\Models\Channel;
use App\Models\User;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
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

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

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
     * @return array<string, string>
     */
    public function matchTypeOptions(): array
    {
        return BotConstructorBlock::matchTypeOptions();
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

    private function loadBlock(?BotConstructorBlock $block): void
    {
        if (! $block instanceof BotConstructorBlock) {
            $this->selectedBlockId = null;

            return;
        }

        $block->loadMissing('channels');

        $this->selectedBlockId = (int) $block->id;
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

    private function findBlock(int $blockId): BotConstructorBlock
    {
        return BotConstructorBlock::query()->findOrFail($blockId);
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
