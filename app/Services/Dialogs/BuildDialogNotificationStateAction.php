<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

class BuildDialogNotificationStateAction
{
    public const PREFERENCE_KEY = 'admin.dialog_notifications';

    public const SCOPE_MINE = 'mine';

    public const SCOPE_MINE_UNASSIGNED = 'mine_unassigned';

    public const SCOPE_ALL = 'all';

    private const ITEM_LIMIT = 10;

    private const CANDIDATE_LIMIT = 250;

    public function __construct(
        private readonly ResolveDialogInboxStatusAction $resolveDialogInboxStatusAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, bool $initialize = false): array
    {
        $preference = $this->resolvePreference($user);
        $scope = $preference['scope'];
        $lastReadMessageId = $preference['last_read_message_id'];
        $latestScopedInboundMessageId = $this->latestScopedInboundMessageId($user, $scope);

        if ($initialize && $lastReadMessageId < 1 && $latestScopedInboundMessageId > 0) {
            $lastReadMessageId = $latestScopedInboundMessageId;
            $this->persistPreference($user, [
                ...$preference,
                'last_read_message_id' => $lastReadMessageId,
            ]);
        }

        $items = $this->notificationItems($user, $scope, $lastReadMessageId);
        $latestNotificationMessageId = (int) ($items[0]['message_id'] ?? 0);

        return [
            'scope' => $scope,
            'scope_options' => $this->scopeOptions(),
            'default_scope' => $this->defaultScopeFor($user),
            'last_read_message_id' => $lastReadMessageId,
            'latest_scoped_inbound_message_id' => $latestScopedInboundMessageId,
            'latest_notification_message_id' => $latestNotificationMessageId,
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function setScope(User $user, string $scope): array
    {
        $preference = $this->resolvePreference($user);

        $this->persistPreference($user, [
            ...$preference,
            'scope' => $this->normalizeScope($scope, $this->defaultScopeFor($user)),
        ]);

        return $this->handle($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(User $user, ?int $messageId = null): array
    {
        $preference = $this->resolvePreference($user);
        $targetMessageId = $messageId !== null && $messageId > 0
            ? $messageId
            : $this->latestScopedInboundMessageId($user, $preference['scope']);

        if ($targetMessageId > $preference['last_read_message_id']) {
            $this->persistPreference($user, [
                ...$preference,
                'last_read_message_id' => $targetMessageId,
            ]);
        }

        return $this->handle($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notificationItems(User $user, string $scope, int $lastReadMessageId): array
    {
        $items = [];
        $seenDialogIds = [];

        foreach ($this->candidateMessages($lastReadMessageId) as $message) {
            $dialog = $message->dialog;

            if (! $dialog instanceof Dialog || isset($seenDialogIds[$dialog->id])) {
                continue;
            }

            if (! $this->dialogMatchesScope($dialog, $user, $scope)) {
                continue;
            }

            if ($this->resolveDialogInboxStatusAction->handle($dialog)->code !== DialogInboxStatusData::CODE_REQUIRES_REPLY) {
                continue;
            }

            $seenDialogIds[$dialog->id] = true;
            $items[] = $this->formatItem($message, $dialog);

            if (count($items) >= self::ITEM_LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * @return EloquentCollection<int, Message>
     */
    private function candidateMessages(int $lastReadMessageId = 0): EloquentCollection
    {
        return Message::query()
            ->with(['dialog.contact.assignedUser', 'dialog.channel', 'channel'])
            ->whereNotNull('dialog_id')
            ->where('id', '>', $lastReadMessageId)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->orderByDesc('id')
            ->limit(self::CANDIDATE_LIMIT)
            ->get();
    }

    private function latestScopedInboundMessageId(User $user, string $scope): int
    {
        foreach ($this->candidateMessages() as $message) {
            $dialog = $message->dialog;

            if ($dialog instanceof Dialog && $this->dialogMatchesScope($dialog, $user, $scope)) {
                return (int) $message->id;
            }
        }

        return 0;
    }

    private function dialogMatchesScope(Dialog $dialog, User $user, string $scope): bool
    {
        $contact = $dialog->contact;

        if (! $contact instanceof Contact || filled($contact->merged_into_contact_id)) {
            return false;
        }

        $assignedUserId = $contact->assigned_user_id;

        return match ($scope) {
            self::SCOPE_MINE => (int) $assignedUserId === (int) $user->id,
            self::SCOPE_ALL => true,
            default => $assignedUserId === null || (int) $assignedUserId === (int) $user->id,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function formatItem(Message $message, Dialog $dialog): array
    {
        return [
            'message_id' => (int) $message->id,
            'dialog_id' => (int) $dialog->id,
            'contact' => $this->contactLabel($dialog->contact),
            'channel' => $this->channelLabel($dialog->channel ?? $message->channel),
            'text' => $this->previewText($message),
            'received_at' => $message->received_at?->format('d.m.Y H:i') ?? $message->created_at?->format('d.m.Y H:i'),
            'url' => DialogResource::getUrl('view', ['record' => $dialog]),
        ];
    }

    private function contactLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return 'Контакт';
        }

        $label = trim(implode(' ', array_filter([
            $contact->first_name,
            $contact->last_name,
        ], filled(...))));

        if (filled($label)) {
            return $label;
        }

        if (filled($contact->name)) {
            return (string) $contact->name;
        }

        return 'Контакт #'.$contact->id;
    }

    private function channelLabel(?Channel $channel): string
    {
        if (! $channel instanceof Channel) {
            return 'Канал';
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'Канал';
    }

    private function previewText(Message $message): string
    {
        $text = trim((string) $message->text);

        return filled($text)
            ? Str::limit($text, 120)
            : 'Новое входящее сообщение';
    }

    /**
     * @return array{scope:string,last_read_message_id:int}
     */
    private function resolvePreference(User $user): array
    {
        $rawPreference = $user->getTablePreference(self::PREFERENCE_KEY) ?? [];
        $defaultScope = $this->defaultScopeFor($user);

        return [
            'scope' => $this->normalizeScope($rawPreference['scope'] ?? null, $defaultScope),
            'last_read_message_id' => max(0, (int) ($rawPreference['last_read_message_id'] ?? 0)),
        ];
    }

    /**
     * @param  array{scope:string,last_read_message_id:int}  $preference
     */
    private function persistPreference(User $user, array $preference): void
    {
        $user->putTablePreference(self::PREFERENCE_KEY, $preference);
    }

    private function defaultScopeFor(User $user): string
    {
        return $user->canManageSystem()
            ? self::SCOPE_ALL
            : self::SCOPE_MINE_UNASSIGNED;
    }

    private function normalizeScope(mixed $scope, string $fallback): string
    {
        return in_array($scope, [
            self::SCOPE_MINE,
            self::SCOPE_MINE_UNASSIGNED,
            self::SCOPE_ALL,
        ], true)
            ? $scope
            : $fallback;
    }

    /**
     * @return array<int, array{value:string,label:string}>
     */
    private function scopeOptions(): array
    {
        return [
            ['value' => self::SCOPE_MINE, 'label' => 'Мои'],
            ['value' => self::SCOPE_MINE_UNASSIGNED, 'label' => 'Мои и свободные'],
            ['value' => self::SCOPE_ALL, 'label' => 'Все'],
        ];
    }
}
