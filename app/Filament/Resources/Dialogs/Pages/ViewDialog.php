<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\LoadDialogMessagesPageAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class ViewDialog extends ViewRecord
{
    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.view-dialog';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var list<array<string, mixed>>
     */
    public array $conversationMessages = [];

    public bool $hasMoreOlderMessages = false;

    public string $dialogReplyText = '';

    /**
     * @var array{sort_at:string,id:int}|null
     */
    public ?array $nextOlderCursor = null;

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
        $contact = $this->getRecord()->contact;

        return [
            ContactResource::getUrl('index') => ContactResource::getBreadcrumb(),
            $this->getContactViewUrl() => $contact?->display_name ?? 'Контакт',
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

        $this->conversationMessages = [
            ...$olderMessages,
            ...$this->conversationMessages,
        ];
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;

        $this->dispatch('dialog-history-older-messages-loaded');
    }

    public function sendDialogReply(): void
    {
        $validated = $this->validate([
            'dialogReplyText' => ['required', 'string', 'max:2000'],
        ]);

        $text = trim((string) ($validated['dialogReplyText'] ?? ''));

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
            );

            $this->dialogReplyText = '';
            $this->appendOutboundMessageToConversation($outboundMessage);
            $this->refreshDialogRecord();
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
            'replyComposer' => $this->getReplyComposerViewData(),
        ];
    }

    protected function initializeConversationHistory(): void
    {
        $page = app(LoadDialogMessagesPageAction::class)->handle($this->getRecord(), null, 50);

        $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
    }

    /**
     * @return array{
     *     canReply:bool,
     *     blockedReason:?string,
     *     canClaim:bool,
     *     autoReplyEnabled:bool,
     *     replyTextModel:string,
     *     replyErrorModel:string,
     *     submitMethod:string
     * }
     */
    protected function getReplyComposerViewData(): array
    {
        $replyOwner = $this->resolveReplyOwnerContact();

        return [
            'canReply' => $this->canCurrentUserReplyToDialog(),
            'blockedReason' => $this->getDialogReplyBlockedReason(),
            'canClaim' => $this->canCurrentUserClaimDialog(),
            'autoReplyEnabled' => $replyOwner?->isAutoReplyEnabled() ?? false,
            'replyTextModel' => 'dialogReplyText',
            'replyErrorModel' => 'dialogReplyText',
            'submitMethod' => 'sendDialogReply',
        ];
    }

    /**
     * @return array{
     *     channel_label:string,
     *     platform_label:string,
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
        $contact = $this->getRecord()->contact;

        return [
            'contact_label' => $contact?->display_name ?? 'Контакт не найден',
            'contact_id' => $contact?->id,
            'phone_label' => $this->resolvePrimaryPhoneRaw($contact) ?? '—',
            'assigned_user_label' => $this->formatAssignedUserLabel($contact),
        ];
    }

    protected function appendOutboundMessageToConversation(Message $message): void
    {
        $message->loadMissing(['channel', 'dialog.channel', 'sentByUser']);

        $outboundMessageViewData = app(BuildConversationFeedViewDataAction::class)->handle(collect([$message]));

        if ($outboundMessageViewData === []) {
            return;
        }

        $this->conversationMessages = [
            ...$this->conversationMessages,
            ...$outboundMessageViewData,
        ];
    }

    protected function refreshDialogRecord(): void
    {
        /** @var Dialog $dialog */
        $dialog = DialogResource::getEloquentQuery()->findOrFail($this->getRecord()->getKey());

        $this->record = $dialog;
    }

    protected function getContactViewUrl(): string
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return ContactResource::getUrl('index');
        }

        return ContactResource::getUrl('index', [
            'tableAction' => 'view',
            'tableActionRecord' => (string) $contact->id,
        ]);
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
        return in_array($this->getDialogOwnershipState(), ['mine', 'unassigned'], true)
            && $this->getDialogRouteBlockedReason() === null;
    }

    protected function canCurrentUserClaimDialog(): bool
    {
        return $this->getDialogOwnershipState() === 'unassigned';
    }

    protected function getDialogReplyBlockedReason(): ?string
    {
        return match ($this->getDialogOwnershipState()) {
            'other' => filled($this->resolveReplyOwnerContact()?->assignedUser?->name)
                ? 'Контакт уже назначен сотруднику '.$this->resolveReplyOwnerContact()->assignedUser->name.'.'
                : 'Контакт уже назначен другому сотруднику.',
            default => $this->getDialogRouteBlockedReason(),
        };
    }

    protected function getDialogOwnershipState(): string
    {
        $contact = $this->resolveReplyOwnerContact();

        if (! $contact instanceof Contact) {
            return 'other';
        }

        $contact->loadMissing('assignedUser');

        if (! $contact->isAssigned()) {
            return 'unassigned';
        }

        $currentUserId = auth()->user()?->id;

        if (($currentUserId !== null) && ((int) $contact->assigned_user_id === (int) $currentUserId)) {
            return 'mine';
        }

        return 'other';
    }

    protected function getDialogRouteBlockedReason(): ?string
    {
        return $this->resolveDialogRouteStatus($this->getRecord())->blockedReason;
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

    protected function resolveDialogRouteStatus(Dialog $dialog): \App\Data\Dialogs\DialogRouteStatusData
    {
        return app(ResolveDialogRouteStatusAction::class)->handle($dialog);
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
}
