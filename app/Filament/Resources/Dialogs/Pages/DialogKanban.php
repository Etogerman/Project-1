<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use App\Services\Dialogs\UpdateDialogStageAction;
use Filament\Actions\Action;
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
    private const INITIAL_VISIBLE_CARDS = 30;

    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.dialog-kanban';

    protected Width|string|null $maxContentWidth = Width::Full;

    public string $selectedChannelId = '';

    public string $selectedAssignedUserId = '';

    public string $selectedRouteStatus = '';

    public string $selectedInboxStatus = '';

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
     * @var array<int, array<string, mixed>>
     */
    protected array $previewFeedCache = [];

    protected function queryString(): array
    {
        return [
            'selectedChannelId' => ['as' => 'channel', 'except' => ''],
            'selectedAssignedUserId' => ['as' => 'assignee', 'except' => ''],
            'selectedRouteStatus' => ['as' => 'route', 'except' => ''],
            'selectedInboxStatus' => ['as' => 'inbox', 'except' => ''],
        ];
    }

    public function mount(): void
    {
        foreach (Dialog::kanbanStages() as $stage) {
            $this->visibleCardsPerStage[$stage] = self::INITIAL_VISIBLE_CARDS;
        }

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

    protected function getHeaderActions(): array
    {
        return [
            Action::make('table')
                ->label('Таблица')
                ->icon('heroicon-m-table-cells')
                ->color('warning')
                ->url(DialogResource::getUrl('index')),
        ];
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
        ], true)) {
            return;
        }

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
        $dialogs = $this->loadFilteredDialogs();

        return [
            'filters' => [
                'channel_options' => $this->channelOptions(),
                'assigned_user_options' => $this->assignedUserOptions(),
                'route_status_options' => $this->routeStatusOptions(),
                'inbox_status_options' => $this->inboxStatusOptions(),
            ],
            'columns' => $this->buildColumns($dialogs),
            'can_manage_stages' => $this->canCurrentUserManageDialogStages(),
        ];
    }

    /**
     * @return Collection<int, Dialog>
     */
    private function loadFilteredDialogs(): Collection
    {
        $query = DialogResource::getTableRecordQuery();

        if ($this->selectedChannelId !== '') {
            $query->where('dialogs.channel_id', (int) $this->selectedChannelId);
        }

        if ($this->selectedAssignedUserId !== '') {
            $query->whereHas('contact', fn (Builder $query): Builder => $query->where('assigned_user_id', (int) $this->selectedAssignedUserId));
        }

        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = $query->get()
            ->filter(fn (Dialog $dialog): bool => $this->matchesRouteStatusFilter($dialog))
            ->filter(fn (Dialog $dialog): bool => $this->matchesInboxStatusFilter($dialog))
            ->values();

        return $dialogs;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @return list<array<string, mixed>>
     */
    private function buildColumns(Collection $dialogs): array
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
                'label' => Dialog::stageLabel($stage),
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
            'channel' => $this->selectedChannelId,
            'assignee' => $this->selectedAssignedUserId,
            'route' => $this->selectedRouteStatus,
            'inbox' => $this->selectedInboxStatus,
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

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @return Collection<int, Dialog>
     */
    private function sortDialogsForColumn(Collection $dialogs, string $columnStage): Collection
    {
        $sortedDialogs = $dialogs->all();

        usort($sortedDialogs, function (Dialog $left, Dialog $right) use ($columnStage): int {
            $leftTuple = [
                $this->promotionSequence($left->id, $columnStage),
                $this->timestampSortKey($left->last_message_at),
                $left->id,
            ];
            $rightTuple = [
                $this->promotionSequence($right->id, $columnStage),
                $this->timestampSortKey($right->last_message_at),
                $right->id,
            ];

            return $rightTuple <=> $leftTuple;
        });

        return collect($sortedDialogs);
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
        if ($dialog->stage === Dialog::STAGE_REQUIRES_REVIEW) {
            return Dialog::STAGE_REQUIRES_REVIEW;
        }

        return $dialog->stage ?? app(ResolveDialogStageAction::class)->handle($dialog);
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

    private function matchesRouteStatusFilter(Dialog $dialog): bool
    {
        if ($this->selectedRouteStatus === '') {
            return true;
        }

        $routeStatus = app(ResolveDialogRouteStatusAction::class)->handle($dialog);

        return match ($this->selectedRouteStatus) {
            'ready' => $routeStatus->code === DialogRouteStatusData::CODE_READY,
            'problem' => $routeStatus->code !== DialogRouteStatusData::CODE_READY,
            default => true,
        };
    }

    private function matchesInboxStatusFilter(Dialog $dialog): bool
    {
        if ($this->selectedInboxStatus === '') {
            return true;
        }

        return app(ResolveDialogInboxStatusAction::class)->handle($dialog)->code === $this->selectedInboxStatus;
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
        $previewMessage = $dialog->previewMessage;

        if (! $previewMessage instanceof Message) {
            return 'Сообщений ещё не было.';
        }

        if (array_key_exists($previewMessage->id, $this->previewFeedCache)) {
            return (string) ($this->previewFeedCache[$previewMessage->id]['display_text'] ?? 'Сообщений ещё не было.');
        }

        $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$previewMessage]));
        $this->previewFeedCache[$previewMessage->id] = $feed[0] ?? [];

        return (string) ($this->previewFeedCache[$previewMessage->id]['display_text'] ?? 'Сообщений ещё не было.');
    }

    private function timestampSortKey(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s.u')
            : '';
    }
}
