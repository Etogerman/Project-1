<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\User;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use App\Services\Dialogs\UpdateDialogStageAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Throwable;

class DialogKanban extends Page
{
    private const INITIAL_VISIBLE_CARDS = 15;

    private const SORT_ACTIVITY_DESC = 'activity_desc';

    private const SORT_ACTIVITY_ASC = 'activity_asc';

    private const SORT_REQUIRES_REPLY_FIRST = 'requires_reply_first';

    private const SORT_UNASSIGNED_FIRST = 'unassigned_first';

    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.dialog-kanban';

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $selectedChannelId = '';

    public string $selectedAssignedUserId = '';

    public string $selectedRouteStatus = '';

    public string $selectedInboxStatus = '';

    public string $search = '';

    public string $selectedSort = self::SORT_ACTIVITY_DESC;

    public bool $filtersPanelOpen = false;

    public bool $sortPanelOpen = false;

    /**
     * @var array<string, int>
     */
    public array $visibleCardsPerStage = [];

    /**
     * @var array<int, array{stage:string,sequence:int}>
     */
    public array $recentMovePromotions = [];

    public int $moveSequence = 0;

    /**
     * @var array<string, string>|null
     */
    protected ?array $dialogFieldLabels = null;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $dialogOptionLabels = [];

    protected function queryString(): array
    {
        return [
            'selectedChannelId' => ['as' => 'channel', 'except' => ''],
            'selectedAssignedUserId' => ['as' => 'assignee', 'except' => ''],
            'selectedRouteStatus' => ['as' => 'route', 'except' => ''],
            'selectedInboxStatus' => ['as' => 'inbox', 'except' => ''],
            'search' => ['except' => ''],
            'selectedSort' => ['as' => 'sort', 'except' => self::SORT_ACTIVITY_DESC],
        ];
    }

    public function mount(): void
    {
        $this->normalizeSelectedSort();

        foreach (Dialog::kanbanStages() as $stage) {
            $this->visibleCardsPerStage[$stage] = self::INITIAL_VISIBLE_CARDS;
        }

        $this->filtersPanelOpen = $this->hasActiveFilters();

        $this->rememberCurrentNavigationUrl();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Диалоги';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Диалоги';
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function getBreadcrumb(): ?string
    {
        return 'Канбан';
    }

    public function loadMoreCards(string $stage): void
    {
        if (! in_array($stage, Dialog::kanbanStages(), true)) {
            return;
        }

        $this->visibleCardsPerStage[$stage] = ($this->visibleCardsPerStage[$stage] ?? self::INITIAL_VISIBLE_CARDS)
            + self::INITIAL_VISIBLE_CARDS;
    }

    public function updated(string $name, mixed $value): void
    {
        if (! in_array($name, [
            'selectedChannelId',
            'selectedAssignedUserId',
            'selectedRouteStatus',
            'selectedInboxStatus',
            'search',
            'selectedSort',
        ], true)) {
            return;
        }

        if ($name === 'selectedSort') {
            $this->normalizeSelectedSort();
        }

        $this->rememberCurrentNavigationUrl();
    }

    public function resetKanbanFilters(): void
    {
        $this->selectedChannelId = '';
        $this->selectedAssignedUserId = '';
        $this->selectedRouteStatus = '';
        $this->selectedInboxStatus = '';
        $this->search = '';
        $this->filtersPanelOpen = false;

        $this->rememberCurrentNavigationUrl();
    }

    public function clearSearch(): void
    {
        $this->search = '';

        $this->rememberCurrentNavigationUrl();
    }

    public function toggleFiltersPanel(): void
    {
        $this->filtersPanelOpen = ! $this->filtersPanelOpen;
    }

    public function toggleSortPanel(): void
    {
        $this->sortPanelOpen = ! $this->sortPanelOpen;
    }

    public function selectKanbanSort(string $sort): void
    {
        $this->selectedSort = array_key_exists($sort, $this->sortOptions())
            ? $sort
            : self::SORT_ACTIVITY_DESC;
        $this->sortPanelOpen = false;

        $this->rememberCurrentNavigationUrl();
    }

    public function moveDialogCard(int $dialogId, string $targetStage): void
    {
        if (! in_array($targetStage, Dialog::kanbanStages(), true)) {
            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();
            $dialog = DialogResource::getEloquentQuery()->findOrFail($dialogId);

            $result = app(UpdateDialogStageAction::class)->handle(
                $dialog,
                $employee,
                $targetStage,
            );

            $this->moveSequence++;
            $this->recentMovePromotions[$dialogId] = [
                'stage' => $result->stage,
                'sequence' => $this->moveSequence,
            ];

            Notification::make()
                ->success()
                ->title('Карточка перемещена')
                ->body('Этап диалога сохранён.')
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось переместить карточку')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось переместить карточку')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'filters' => [
                'channel_options' => $this->channelOptions(),
                'assigned_user_options' => $this->assignedUserOptions(),
                'route_status_options' => $this->routeStatusOptions(),
                'inbox_status_options' => $this->inboxStatusOptions(),
            ],
            'sort_options' => $this->sortOptions(),
            'sort_state' => [
                'selected' => $this->selectedSort,
                'label' => $this->sortOptions()[$this->selectedSort] ?? $this->sortOptions()[self::SORT_ACTIVITY_DESC],
                'is_default' => $this->selectedSort === self::SORT_ACTIVITY_DESC,
            ],
            'filter_state' => [
                'active_count' => $this->activeFilterCount(),
                'has_active_filters' => $this->hasActiveFilters(),
            ],
            'columns' => $this->buildColumns(),
            'field_labels' => $this->getDialogFieldLabels(),
            'can_manage_stages' => $this->canCurrentUserManageDialogStages(),
            'table_url' => DialogResource::getUrl('index'),
        ];
    }

    private function filteredDialogsQuery(): Builder
    {
        return $this->applyCommonFilters(DialogResource::getKanbanRecordQuery());
    }

    private function filteredDialogIdsQuery(): Builder
    {
        return $this->applyCommonFilters(
            Dialog::query()
                ->whereHas('contact', fn (Builder $query): Builder => $query->whereNull('merged_into_contact_id')),
        );
    }

    private function applyCommonFilters(Builder $query): Builder
    {
        if ($this->selectedChannelId !== '') {
            $query->where('dialogs.channel_id', (int) $this->selectedChannelId);
        }

        if ($this->selectedAssignedUserId !== '') {
            $query->whereHas('contact', fn (Builder $query): Builder => $query->where('assigned_user_id', (int) $this->selectedAssignedUserId));
        }

        if (trim($this->search) !== '') {
            DialogResource::applyTableSearch($query, $this->search);
        }

        if ($this->selectedRouteStatus === 'ready') {
            $query->whereRouteReady();
        }

        if ($this->selectedRouteStatus === 'problem') {
            $query->whereRouteProblem();
        }

        if ($this->selectedInboxStatus !== '') {
            DialogResource::applyInboxStatusFilter($query, $this->selectedInboxStatus);
        }

        return $query;
    }

    /**
     * @return Collection<int, Dialog>
     */
    private function loadFilteredDialogs(): Collection
    {
        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = $this->filteredDialogsQuery()->get()->values();

        return $dialogs;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildColumns(): array
    {
        if ($this->shouldBuildColumnsFromCollection()) {
            return $this->buildColumnsFromCollection($this->loadFilteredDialogs());
        }

        return $this->buildSqlColumns();
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @return list<array<string, mixed>>
     */
    private function buildColumnsFromCollection(Collection $dialogs): array
    {
        $columns = [];

        foreach (Dialog::kanbanStages() as $stage) {
            $dialogsInColumn = $this->sortDialogsForColumn(
                $dialogs->filter(fn (Dialog $dialog): bool => $this->resolveKanbanColumnStage($dialog) === $stage)->values(),
                $stage,
            );
            $visibleCount = $this->visibleCardsPerStage[$stage] ?? self::INITIAL_VISIBLE_CARDS;

            $columns[] = [
                'stage' => $stage,
                'label' => $this->dialogOptionLabel('stage', $stage, Dialog::stageLabel($stage)),
                'tone' => Dialog::stageTone($stage),
                'count' => $dialogsInColumn->count(),
                'has_more' => $dialogsInColumn->count() > $visibleCount,
                'cards' => $dialogsInColumn
                    ->take($visibleCount)
                    ->map(fn (Dialog $dialog): array => $this->buildCardViewData($dialog, $stage))
                    ->values()
                    ->all(),
            ];
        }

        return $columns;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSqlColumnShell(string $stage): array
    {
        $visibleCount = $this->visibleCardsPerStage[$stage] ?? self::INITIAL_VISIBLE_CARDS;
        $countQuery = $this->filteredDialogIdsQuery();
        DialogResource::applyStageFilter($countQuery, $stage);

        $count = (int) $countQuery->count('dialogs.id');

        return [
            'stage' => $stage,
            'label' => $this->dialogOptionLabel('stage', $stage, Dialog::stageLabel($stage)),
            'tone' => Dialog::stageTone($stage),
            'count' => $count,
            'has_more' => $count > $visibleCount,
            'visible_ids' => $this->visibleDialogIdsForStage($stage, $visibleCount),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSqlColumns(): array
    {
        $columnShells = $this->buildSqlColumnShells();
        $dialogsById = $this->loadVisibleDialogsById($this->visibleDialogIds($columnShells));

        return array_map(
            fn (array $column): array => [
                'stage' => $column['stage'],
                'label' => $column['label'],
                'tone' => $column['tone'],
                'count' => $column['count'],
                'has_more' => $column['has_more'],
                'cards' => collect($column['visible_ids'])
                    ->map(fn (int $dialogId): ?Dialog => $dialogsById[$dialogId] ?? null)
                    ->filter()
                    ->map(fn (Dialog $dialog): array => $this->buildCardViewData($dialog, $column['stage']))
                    ->values()
                    ->all(),
            ],
            $columnShells,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildSqlColumnShells(): array
    {
        return array_map(
            fn (string $stage): array => $this->buildSqlColumnShell($stage),
            Dialog::kanbanStages(),
        );
    }

    /**
     * @return list<int>
     */
    private function visibleDialogIdsForStage(string $stage, int $visibleCount): array
    {
        $query = $this->filteredDialogIdsQuery();
        DialogResource::applyStageFilter($query, $stage);
        $this->applySelectedSortToQuery($query);

        return $query
            ->limit($visibleCount)
            ->pluck('dialogs.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $columnShells
     * @return list<int>
     */
    private function visibleDialogIds(array $columnShells): array
    {
        return array_values(array_unique(array_merge(
            ...array_map(
                fn (array $column): array => $column['visible_ids'],
                $columnShells,
            ),
        )));
    }

    /**
     * @param  list<int>  $dialogIds
     * @return array<int, Dialog>
     */
    private function loadVisibleDialogsById(array $dialogIds): array
    {
        if ($dialogIds === []) {
            return [];
        }

        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = DialogResource::getKanbanRecordQuery()
            ->whereIn('dialogs.id', $dialogIds)
            ->get()
            ->keyBy('id');

        return $dialogs->all();
    }

    private function shouldBuildColumnsFromCollection(): bool
    {
        return $this->recentMovePromotions !== []
            || in_array($this->selectedSort, [
                self::SORT_REQUIRES_REPLY_FIRST,
                self::SORT_UNASSIGNED_FIRST,
            ], true);
    }

    private function applySelectedSortToQuery(Builder $query): void
    {
        if ($this->selectedSort === self::SORT_ACTIVITY_ASC) {
            $query
                ->orderByRaw('dialogs.last_message_at is not null asc')
                ->orderBy('dialogs.last_message_at')
                ->orderBy('dialogs.id');

            return;
        }

        $query
            ->orderByRaw('dialogs.last_message_at is null asc')
            ->orderByDesc('dialogs.last_message_at')
            ->orderByDesc('dialogs.id');
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCardViewData(Dialog $dialog, string $columnStage): array
    {
        $inboxStatus = app(ResolveDialogInboxStatusAction::class)->handle($dialog);
        $routeStatus = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        return [
            'id' => $dialog->id,
            'contact_label' => $this->resolveContactLabel($dialog),
            'channel_label' => $this->formatChannelLabel($dialog),
            'assigned_user_label' => filled($dialog->contact?->assignedUser?->name)
                ? (string) $dialog->contact->assignedUser->name
                : 'Свободен',
            'preview_text' => $this->resolvePreviewText($dialog),
            'activity_label' => $dialog->last_message_at?->format('d.m.Y H:i') ?? '—',
            'inbox_status_label' => $inboxStatus->label,
            'inbox_status_tone' => $inboxStatus->tone,
            'route_status_label' => $routeStatus->label,
            'route_status_tone' => $routeStatus->tone,
            'view_url' => $this->buildDialogViewUrl($dialog),
            'allowed_target_stages' => $this->allowedTargetStages($dialog, $columnStage),
        ];
    }

    private function buildDialogViewUrl(Dialog $dialog): string
    {
        $url = DialogResource::getUrl('view', ['record' => $dialog]);

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query([
            'back_to' => $this->currentKanbanUrl(),
        ]);
    }

    private function currentKanbanUrl(): string
    {
        $baseUrl = DialogResource::getUrl('kanban');
        $query = array_filter([
            'search' => trim($this->search),
            'channel' => $this->selectedChannelId,
            'assignee' => $this->selectedAssignedUserId,
            'route' => $this->selectedRouteStatus,
            'inbox' => $this->selectedInboxStatus,
            'sort' => $this->selectedSort === self::SORT_ACTIVITY_DESC ? '' : $this->selectedSort,
        ], fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($query === []) {
            return $baseUrl;
        }

        return $baseUrl.'?'.http_build_query($query);
    }

    private function rememberCurrentNavigationUrl(): void
    {
        DialogResource::rememberNavigationUrl($this->currentKanbanUrl());
    }

    private function activeFilterCount(): int
    {
        return count(array_filter([
            $this->selectedChannelId,
            $this->selectedAssignedUserId,
            $this->selectedRouteStatus,
            $this->selectedInboxStatus,
            trim($this->search),
        ], fn (string $value): bool => $value !== ''));
    }

    private function hasActiveFilters(): bool
    {
        return $this->activeFilterCount() > 0;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @return Collection<int, Dialog>
     */
    private function sortDialogsForColumn(Collection $dialogs, string $columnStage): Collection
    {
        $sortedDialogs = $dialogs->all();

        usort($sortedDialogs, function (Dialog $left, Dialog $right) use ($columnStage): int {
            $promotionCompare = $this->compareDesc(
                $this->promotionSequence($left->id, $columnStage),
                $this->promotionSequence($right->id, $columnStage),
            );

            if ($promotionCompare !== 0) {
                return $promotionCompare;
            }

            return $this->compareDialogsBySelectedSort($left, $right);
        });

        return collect($sortedDialogs);
    }

    /**
     * @return array<string, string>
     */
    private function sortOptions(): array
    {
        return [
            self::SORT_ACTIVITY_DESC => 'Сначала новые',
            self::SORT_ACTIVITY_ASC => 'Сначала старые',
            self::SORT_REQUIRES_REPLY_FIRST => 'Сначала требуют ответа',
            self::SORT_UNASSIGNED_FIRST => 'Свободные сверху',
        ];
    }

    private function normalizeSelectedSort(): void
    {
        if (! array_key_exists($this->selectedSort, $this->sortOptions())) {
            $this->selectedSort = self::SORT_ACTIVITY_DESC;
        }
    }

    private function compareDialogsBySelectedSort(Dialog $left, Dialog $right): int
    {
        return match ($this->selectedSort) {
            self::SORT_ACTIVITY_ASC => $this->compareAsc(
                [$this->timestampSortKey($left->last_message_at), $left->id],
                [$this->timestampSortKey($right->last_message_at), $right->id],
            ),
            self::SORT_REQUIRES_REPLY_FIRST => $this->compareDesc(
                [$this->requiresReplySortKey($left), $this->timestampSortKey($left->last_message_at), $left->id],
                [$this->requiresReplySortKey($right), $this->timestampSortKey($right->last_message_at), $right->id],
            ),
            self::SORT_UNASSIGNED_FIRST => $this->compareDesc(
                [$this->unassignedSortKey($left), $this->timestampSortKey($left->last_message_at), $left->id],
                [$this->unassignedSortKey($right), $this->timestampSortKey($right->last_message_at), $right->id],
            ),
            default => $this->compareDesc(
                [$this->timestampSortKey($left->last_message_at), $left->id],
                [$this->timestampSortKey($right->last_message_at), $right->id],
            ),
        };
    }

    private function compareAsc(mixed $left, mixed $right): int
    {
        return $left <=> $right;
    }

    private function compareDesc(mixed $left, mixed $right): int
    {
        return $right <=> $left;
    }

    private function requiresReplySortKey(Dialog $dialog): int
    {
        return app(ResolveDialogInboxStatusAction::class)->handle($dialog)->code === DialogInboxStatusData::CODE_REQUIRES_REPLY
            ? 1
            : 0;
    }

    private function unassignedSortKey(Dialog $dialog): int
    {
        return $dialog->contact?->assigned_user_id === null ? 1 : 0;
    }

    private function promotionSequence(int $dialogId, string $columnStage): int
    {
        $promotion = $this->recentMovePromotions[$dialogId] ?? null;

        if (! is_array($promotion) || ($promotion['stage'] ?? null) !== $columnStage) {
            return 0;
        }

        return (int) ($promotion['sequence'] ?? 0);
    }

    private function resolveKanbanColumnStage(Dialog $dialog): string
    {
        return app(ResolveDialogStageAction::class)->handle($dialog);
    }

    /**
     * @return list<string>
     */
    private function allowedTargetStages(Dialog $dialog, string $currentColumnStage): array
    {
        if (! $this->canCurrentUserManageDialogStages()) {
            return [];
        }

        if (! $dialog->hasCompleteStageHistoryRouteContext()) {
            return [];
        }

        return array_values(array_filter(
            Dialog::allowedOperatorTransitionTargets($currentColumnStage),
            fn (string $stage): bool => $this->canMoveDialogToStage($dialog, $currentColumnStage, $stage),
        ));
    }

    private function canMoveDialogToStage(Dialog $dialog, string $currentColumnStage, string $targetStage): bool
    {
        if ($currentColumnStage === $targetStage) {
            return false;
        }

        if (! Dialog::canManuallyTransition($currentColumnStage, $targetStage)) {
            return false;
        }

        if (Dialog::isManualStage($targetStage) && ! $dialog->hasCompleteStageHistoryRouteContext()) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    private function channelOptions(): array
    {
        return Channel::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->mapWithKeys(fn (Channel $channel): array => [(string) $channel->id => $channel->display_title])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function assignedUserOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->canBeAssignedToContacts())
            ->mapWithKeys(fn (User $user): array => [(string) $user->id => (string) $user->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function routeStatusOptions(): array
    {
        return [
            'ready' => 'Маршрут готов',
            'problem' => 'Проблема маршрута',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function inboxStatusOptions(): array
    {
        return [
            DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            DialogInboxStatusData::CODE_NO_NEW => 'Нет новых',
        ];
    }

    /**
     * @return array<string, string>
     */
    private function getDialogFieldLabels(): array
    {
        return $this->dialogFieldLabels ??= FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_DIALOG);
    }

    private function dialogOptionLabel(string $fieldKey, mixed $value, string $fallback): string
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $fallback);
    }

    private function canCurrentUserManageDialogStages(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canReplyInDialogs();
    }

    private function resolveCurrentEmployee(): User
    {
        /** @var User|null $employee */
        $employee = auth()->user();

        abort_unless($employee instanceof User, 403);

        return $employee;
    }

    private function resolveContactLabel(Dialog $dialog): string
    {
        if ($dialog->contact === null) {
            return 'Контакт не найден';
        }

        return app(ResolveContactDisplayNameAction::class)->handle($dialog->contact, $dialog);
    }

    private function formatChannelLabel(Dialog $dialog): string
    {
        $channel = $dialog->channel;

        if ($channel === null) {
            return 'Неизвестный канал';
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'Неизвестный канал';
    }

    private function resolvePreviewText(Dialog $dialog): string
    {
        return filled($dialog->last_message_preview)
            ? (string) $dialog->last_message_preview
            : 'Сообщений ещё не было.';
    }

    private function timestampSortKey(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s.u')
            : '';
    }
}
