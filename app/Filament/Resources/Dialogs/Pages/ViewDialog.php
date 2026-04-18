<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bots\ContactIdentityAvatarStorage;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\LoadDialogMessagesPageAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\UpdateDialogInboxStatusAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ViewDialog extends ViewRecord
{
    public const CONVERSATION_DISPLAY_MODE_FORMATTED = 'formatted';

    public const CONVERSATION_DISPLAY_MODE_HTML = 'html';

    public const LIVE_REFRESH_INTERVAL_MS = 5000;

    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.view-dialog';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var list<array<string, mixed>>
     */
    public array $conversationMessages = [];

    public bool $hasMoreOlderMessages = false;

    public string $dialogReplyText = '';

    public string $dialogReplyFormat = Message::TEXT_FORMAT_PLAIN_TEXT;

    public string $dialogInboxStatusSelection = DialogInboxStatusData::CODE_NO_NEW;

    public string $conversationDisplayMode = self::CONVERSATION_DISPLAY_MODE_FORMATTED;

    /**
     * @var array{sort_at:string,id:int}|null
     */
    public ?array $nextOlderCursor = null;

    public ?int $latestKnownMessageId = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->initializeConversationHistory();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Диалог';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Диалог';
    }

    public function getSubheading(): ?string
    {
        return $this->formatChannelLabel($this->getRecord()->channel, 'Неизвестный канал');
    }

    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    public function getBreadcrumb(): string
    {
        return 'Диалог: '.$this->formatChannelLabel($this->getRecord()->channel, 'Неизвестный канал');
    }

    public function getBreadcrumbs(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            ContactResource::getUrl('index') => ContactResource::getBreadcrumb(),
            $this->getContactViewUrl() => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт',
            $this->getBreadcrumb(),
        ];
    }

    public function loadOlderMessages(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $page = app(LoadDialogMessagesPageAction::class)->handle(
            $this->getRecord(),
            $this->nextOlderCursor,
            50,
        );

        $olderMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);

        if ($olderMessages === []) {
            $this->hasMoreOlderMessages = false;
            $this->nextOlderCursor = null;

            return;
        }

        $this->prependConversationMessageViewData($olderMessages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->syncNextOlderCursorToVisibleConversationStart();

        $this->dispatch('dialog-history-older-messages-loaded');
    }

    public function refreshDialogViewData(): void
    {
        $this->refreshDialogRecord();
        $this->syncDialogInboxStatusSelection();

        $appendedCount = $this->appendLatestConversationMessages();

        $this->dispatch('dialog-history-refreshed', appendedCount: $appendedCount);
    }

    public function updateDialogInboxStatus(): void
    {
        try {
            $employee = $this->resolveCurrentEmployee();

            $result = app(UpdateDialogInboxStatusAction::class)->handle(
                $this->getRecord(),
                $employee,
                $this->dialogInboxStatusSelection,
            );

            if ($result->historyMessage instanceof Message) {
                $result->historyMessage->loadMissing(['channel', 'dialog.channel', 'sentByUser']);
                $this->appendOutboundMessageToConversation($result->historyMessage);
            }

            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->success()
                ->title('Статус обновлён')
                ->body('Статус диалога сохранён и добавлен в историю.')
                ->send();
        } catch (ValidationException $exception) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function sendDialogReply(): void
    {
        $validated = $this->validate([
            'dialogReplyText' => ['required', 'string', 'max:2000'],
            'dialogReplyFormat' => ['required', 'string', Rule::in(array_keys(Message::textFormatOptions()))],
        ]);

        $text = trim((string) ($validated['dialogReplyText'] ?? ''));
        $textFormat = Message::normalizeTextFormat($validated['dialogReplyFormat'] ?? null);

        if ($text === '') {
            throw ValidationException::withMessages([
                'dialogReplyText' => 'Текст ответа обязателен.',
            ]);
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
                $this->getRecord(),
                $employee,
                $text,
                $textFormat,
            );

            $this->dialogReplyText = '';
            $this->appendOutboundMessageToConversation($outboundMessage);
            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();
            $this->dispatch('dialog-reply-sent');

            Notification::make()
                ->success()
                ->title('Ответ отправлен')
                ->body('Сообщение отправлено и сохранено в истории диалога.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось отправить ответ')
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
            'dialogHeader' => $this->getDialogHeaderViewData(),
            'contactSummary' => $this->getContactSummaryViewData(),
            'contactUrl' => $this->getContactViewUrl(),
            'dialogInboxStatus' => $this->getDialogInboxStatusViewData(),
            'conversationDisplayModeOptions' => $this->getConversationDisplayModeOptions(),
            'liveRefreshPollIntervalMs' => static::LIVE_REFRESH_INTERVAL_MS,
            'replyComposer' => $this->getReplyComposerViewData(),
        ];
    }

    protected function initializeConversationHistory(): void
    {
        $page = app(LoadDialogMessagesPageAction::class)->handle($this->getRecord(), null, 50);

        $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);
        $this->syncDialogInboxStatusSelection();
    }

    /**
     * @return array{
     *     isVisible:bool,
     *     canReply:bool,
     *     blockedReason:?string,
     *     autoReplyEnabled:bool,
     *     replyTextModel:string,
     *     replyFormatModel:string,
     *     replyFormatOptions:array<string, string>,
     *     replyErrorModel:string,
     *     submitMethod:string
     * }
     */
    protected function getReplyComposerViewData(): array
    {
        $replyOwner = $this->resolveReplyOwnerContact();

        return [
            'isVisible' => $this->canCurrentUserManageDialogReplies(),
            'canReply' => $this->canCurrentUserReplyToDialog(),
            'blockedReason' => $this->getDialogReplyBlockedReason(),
            'autoReplyEnabled' => $replyOwner?->isAutoReplyEnabled() ?? false,
            'replyTextModel' => 'dialogReplyText',
            'replyFormatModel' => 'dialogReplyFormat',
            'replyFormatOptions' => Message::textFormatOptions(),
            'replyErrorModel' => 'dialogReplyText',
            'submitMethod' => 'sendDialogReply',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getConversationDisplayModeOptions(): array
    {
        return [
            self::CONVERSATION_DISPLAY_MODE_FORMATTED => 'Форматированный',
            self::CONVERSATION_DISPLAY_MODE_HTML => 'HTML',
        ];
    }

    /**
     * @return array{
     *     current_label:string,
     *     current_tone:string,
     *     is_editable:bool,
     *     status_model:string,
     *     update_method:string,
     *     options:array<string, string>
     * }
     */
    protected function getDialogInboxStatusViewData(): array
    {
        $status = $this->resolveDialogInboxStatus($this->getRecord());

        return [
            'current_label' => $status->label,
            'current_tone' => $status->tone,
            'is_editable' => $this->canCurrentUserManageDialogReplies()
                && $status->code !== DialogInboxStatusData::CODE_NO_NEW,
            'status_model' => 'dialogInboxStatusSelection',
            'update_method' => 'updateDialogInboxStatus',
            'options' => $this->getDialogInboxStatusOptions($status),
        ];
    }

    /**
     * @return array{
     *     channel_label:string,
     *     platform_label:string,
     *     avatar_url:?string,
     *     avatar_fallback_label:?string,
     *     messenger_name_label:string,
     *     route_source_label:string,
     *     external_chat_id_label:string,
     *     route_status_label:string,
     *     route_status_tone:string,
     *     phone_label:string
     * }
     */
    protected function getDialogHeaderViewData(): array
    {
        $dialog = $this->getRecord();
        $routeStatus = $this->resolveDialogRouteStatus($dialog);

        return [
            'channel_label' => $this->formatChannelLabel($dialog->channel, 'Неизвестный канал'),
            'platform_label' => $dialog->channel?->platform !== null
                ? (Channel::platformOptions()[$dialog->channel->platform] ?? $dialog->channel->platform)
                : '—',
            'avatar_url' => $this->resolveDialogAvatarUrl($dialog),
            'avatar_fallback_label' => $this->formatDialogAvatarFallbackLabel($dialog),
            'messenger_name_label' => $this->formatDialogMessengerNameLabel($dialog),
            'route_source_label' => $this->formatDialogRouteIdentityLabel($dialog),
            'external_chat_id_label' => $dialog->external_chat_id ?: 'Не задан',
            'route_status_label' => $routeStatus->label,
            'route_status_tone' => $routeStatus->tone,
            'phone_label' => $this->formatDialogPhoneLabel($dialog),
        ];
    }

    /**
     * @return array{
     *     contact_label:string,
     *     contact_id:int|null,
     *     phone_label:string,
     *     assigned_user_label:string
     * }
     */
    protected function getContactSummaryViewData(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            'contact_label' => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт не найден',
            'contact_id' => $contact?->id,
            'phone_label' => $this->resolvePrimaryPhoneRaw($contact) ?? '—',
            'assigned_user_label' => $this->formatAssignedUserLabel($contact),
        ];
    }

    protected function appendOutboundMessageToConversation(Message $message): void
    {
        $message->loadMissing(['channel', 'dialog.channel', 'sentByUser']);

        $this->appendConversationMessages(collect([$message]));
    }

    protected function refreshDialogRecord(): void
    {
        /** @var Dialog $dialog */
        $dialog = DialogResource::getEloquentQuery()->findOrFail($this->getRecord()->getKey());

        $this->record = $dialog;
    }

    protected function syncDialogInboxStatusSelection(): void
    {
        $this->dialogInboxStatusSelection = $this->resolveDialogInboxStatus($this->getRecord())->code;
    }

    protected function appendLatestConversationMessages(): int
    {
        if ($this->conversationMessages === []) {
            $page = app(LoadDialogMessagesPageAction::class)->handle($this->getRecord(), null, 50);

            if ($page->messages->isEmpty()) {
                $this->hasMoreOlderMessages = false;
                $this->nextOlderCursor = null;
                $this->latestKnownMessageId = null;

                return 0;
            }

            $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
            $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
            $this->nextOlderCursor = $page->nextOlderCursor;
            $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);

            return count($this->conversationMessages);
        }

        $messages = app(LoadDialogMessagesPageAction::class)->loadMessagesAddedAfterId(
            $this->getRecord(),
            $this->latestKnownMessageId,
            50,
        );

        return $this->appendConversationMessages($messages);
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function appendConversationMessages(Collection $messages): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        $existingMessageIds = collect($this->conversationMessages)
            ->pluck('id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $newMessages = $messages
            ->filter(fn (Message $message): bool => ! $existingMessageIds->has($message->id))
            ->values();

        if ($newMessages->isEmpty()) {
            $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($messages) ?? 0) ?: null;

            return 0;
        }

        $newMessageViewData = app(BuildConversationFeedViewDataAction::class)->handle($newMessages);

        if ($newMessageViewData === []) {
            return 0;
        }

        $this->conversationMessages = [
            ...$this->conversationMessages,
            ...$newMessageViewData,
        ];
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
        $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($newMessages) ?? 0) ?: null;
        $this->syncNextOlderCursorToVisibleConversationStart();

        return count($newMessageViewData);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    protected function prependConversationMessageViewData(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $existingMessageIds = collect($this->conversationMessages)
            ->pluck('id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->flip();

        $olderMessageViewData = collect($messages)
            ->filter(function (array $message) use ($existingMessageIds): bool {
                return ! $existingMessageIds->has((int) ($message['id'] ?? 0));
            })
            ->values()
            ->all();

        if ($olderMessageViewData === []) {
            return;
        }

        $this->conversationMessages = [
            ...$olderMessageViewData,
            ...$this->conversationMessages,
        ];
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function sortConversationMessages(array $messages): array
    {
        usort($messages, function (array $left, array $right): int {
            return strcmp((string) ($left['sort_key'] ?? ''), (string) ($right['sort_key'] ?? ''));
        });

        return array_values($messages);
    }

    protected function syncNextOlderCursorToVisibleConversationStart(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $oldestVisibleMessage = $this->conversationMessages[0] ?? null;

        if (
            ! is_array($oldestVisibleMessage)
            || blank($oldestVisibleMessage['sort_at_iso'] ?? null)
            || blank($oldestVisibleMessage['id'] ?? null)
        ) {
            return;
        }

        $this->nextOlderCursor = [
            'sort_at' => (string) $oldestVisibleMessage['sort_at_iso'],
            'id' => (int) $oldestVisibleMessage['id'],
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function resolveLatestKnownMessageId(Collection $messages): ?int
    {
        $latestMessageId = $messages->max('id');

        return is_numeric($latestMessageId)
            ? (int) $latestMessageId
            : null;
    }

    protected function getContactViewUrl(): string
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return ContactResource::getUrl('index');
        }

        return ContactResource::getUrl('view', ['record' => $contact]);
    }

    protected function resolveCurrentEmployee(): User
    {
        /** @var User|null $employee */
        $employee = auth()->user();

        if (! $employee instanceof User) {
            throw new RuntimeException('Не удалось определить текущего сотрудника.');
        }

        return $employee;
    }

    protected function resolveReplyOwnerContact(): ?Contact
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return null;
        }

        return app(ResolveRootContactAction::class)->handle($contact);
    }

    protected function canCurrentUserReplyToDialog(): bool
    {
        if (! $this->canCurrentUserManageDialogReplies()) {
            return false;
        }

        return $this->getDialogRouteBlockedReason() === null;
    }

    protected function canCurrentUserManageDialogReplies(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canReplyInDialogs();
    }

    protected function getDialogReplyBlockedReason(): ?string
    {
        return $this->getDialogRouteBlockedReason();
    }

    protected function getDialogRouteBlockedReason(): ?string
    {
        return $this->resolveDialogRouteStatus($this->getRecord())->blockedReason;
    }

    /**
     * @return array<string, string>
     */
    protected function getDialogInboxStatusOptions(DialogInboxStatusData $status): array
    {
        return match ($status->code) {
            DialogInboxStatusData::CODE_REQUIRES_REPLY => [
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            ],
            DialogInboxStatusData::CODE_NOT_REQUIRED => [
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            ],
            default => [
                DialogInboxStatusData::CODE_NO_NEW => 'Нет новых',
            ],
        };
    }

    protected function resolvePrimaryPhoneRaw(?Contact $contact): ?string
    {
        if (! $contact instanceof Contact) {
            return null;
        }

        $primaryPhone = $contact->phoneNumbers->first();

        return $primaryPhone?->phone_raw;
    }

    protected function formatAssignedUserLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return 'Свободен';
        }

        return filled($contact->assignedUser?->name)
            ? (string) $contact->assignedUser->name
            : 'Свободен';
    }

    protected function formatChannelLabel(?Channel $channel, string $fallback = '—'): string
    {
        if ($channel === null) {
            return $fallback;
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: $fallback;
    }

    protected function resolveDialogRouteStatus(Dialog $dialog): DialogRouteStatusData
    {
        return app(ResolveDialogRouteStatusAction::class)->handle($dialog);
    }

    protected function resolveDialogInboxStatus(Dialog $dialog): DialogInboxStatusData
    {
        return app(ResolveDialogInboxStatusAction::class)->handle($dialog);
    }

    protected function formatDialogPhoneLabel(Dialog $dialog): string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return 'Телефон в этом канале не подтвержден';
    }

    protected function formatDialogMessengerNameLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;

        if (filled($identity?->display_name)) {
            return trim((string) $identity->display_name);
        }

        if (filled($identity?->external_username)) {
            return '@'.ltrim((string) $identity->external_username, '@');
        }

        if (filled($identity?->external_user_id)) {
            return 'ID: '.$identity->external_user_id;
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return '—';
    }

    protected function formatDialogRouteIdentityLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;
        $parts = [];

        if (filled($identity?->external_user_id)) {
            $parts[] = 'ID: '.$identity->external_user_id;
        }

        if (filled($identity?->external_username)) {
            $parts[] = '@'.ltrim((string) $identity->external_username, '@');
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return 'Не задан';
    }

    protected function resolveDialogAvatarUrl(Dialog $dialog): ?string
    {
        $avatarPath = $dialog->currentContactIdentity?->avatar_path;

        if (! filled($avatarPath)) {
            return null;
        }

        $avatarStorage = app(ContactIdentityAvatarStorage::class);

        if ($avatarStorage->exists($avatarPath)) {
            return $avatarStorage->url($avatarPath);
        }

        $legacyDisk = Storage::disk('public');

        if (! $legacyDisk->exists($avatarPath)) {
            return null;
        }

        return $legacyDisk->url($avatarPath);
    }

    protected function formatDialogAvatarFallbackLabel(Dialog $dialog): ?string
    {
        $identity = $dialog->currentContactIdentity;
        $candidate = null;

        if (filled($identity?->display_name)) {
            $candidate = trim((string) $identity->display_name);
        } elseif (filled($identity?->external_username)) {
            $candidate = ltrim((string) $identity->external_username, '@');
        }

        if (! filled($candidate)) {
            return null;
        }

        $parts = preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($parts) && count($parts) > 1) {
            $initials = collect($parts)
                ->take(2)
                ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');

            return $initials !== '' ? $initials : null;
        }

        $initials = mb_strtoupper(mb_substr($candidate, 0, 2));

        return $initials !== '' ? $initials : null;
    }
}
