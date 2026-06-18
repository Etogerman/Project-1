<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Str;

class BuildDialogNotificationStateAction
{
    public const PREFERENCE_KEY = 'admin.dialog_notifications';

    public const SCOPE_MINE = 'mine';

    public const SCOPE_MINE_UNASSIGNED = 'mine_unassigned';

    public const SCOPE_ALL = 'all';

    private const ITEM_LIMIT = 10;

    private const DISMISSED_MESSAGE_LIMIT = 250;

    public function __construct(
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(User $user, bool $initialize = false): array
    {
        $preference = $this->resolvePreference($user);
        $scope = $preference['scope'];
        $latestScopedInboundBoundary = null;

        if ($initialize && $preference['last_read_message_id'] < 1) {
            $latestScopedInboundBoundary = $this->latestScopedInboundMessageBoundary($user, $scope);

            if ($latestScopedInboundBoundary['id'] > 0) {
                $preference = [
                    ...$preference,
                    'last_read_message_id' => $latestScopedInboundBoundary['id'],
                    'last_read_message_sort_at' => $latestScopedInboundBoundary['sort_at'],
                ];

                $this->persistPreference($user, $preference);
            }
        }

        $notificationQuery = $this->notificationDialogQuery($user, $scope, $preference);
        $count = (clone $notificationQuery)->count();
        $items = $this->notificationItems($notificationQuery);
        $latestNotificationMessageId = (int) ($items[0]['message_id'] ?? 0);
        $latestNotificationSortAt = $items[0]['sort_at'] ?? null;

        return [
            'scope' => $scope,
            'scope_options' => $this->scopeOptions(),
            'default_scope' => $this->defaultScopeFor($user),
            'last_read_message_id' => $preference['last_read_message_id'],
            'last_read_message_sort_at' => $preference['last_read_message_sort_at'],
            'latest_scoped_inbound_message_id' => (int) ($latestScopedInboundBoundary['id'] ?? 0),
            'latest_notification_message_id' => $latestNotificationMessageId,
            'latest_notification_sort_at' => $latestNotificationSortAt,
            'count' => $count,
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
        $targetBoundary = [
            'id' => $preference['last_read_message_id'],
            'sort_at' => $preference['last_read_message_sort_at'],
        ];

        if ($messageId !== null && $messageId > 0) {
            $notificationBoundary = $this->notificationMessageBoundary($user, $preference['scope'], $preference, $messageId);

            if ($notificationBoundary !== null) {
                $this->persistPreference($user, [
                    ...$preference,
                    'dismissed_message_ids' => $this->appendDismissedMessageId(
                        $preference['dismissed_message_ids'],
                        $notificationBoundary['id'],
                    ),
                ]);
            }

            return $this->handle($user);
        } else {
            $targetBoundary = $this->latestNotificationMessageBoundary($user, $preference['scope'], $preference, includeDismissed: true)
                ?? $targetBoundary;
        }

        if ($this->boundaryIsAfterPreference($targetBoundary, $preference)) {
            $this->persistPreference($user, [
                ...$preference,
                'last_read_message_id' => $targetBoundary['id'],
                'last_read_message_sort_at' => $targetBoundary['sort_at'],
                'dismissed_message_ids' => [],
            ]);
        }

        return $this->handle($user);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function notificationItems(Builder $notificationQuery): array
    {
        $dialogs = $this->orderedByLatestInbound(
            $this->withLatestInboundProjection($notificationQuery),
        )
            ->limit(self::ITEM_LIMIT)
            ->get();

        $messages = $this->messagesById(
            $dialogs
                ->pluck('latest_inbound_user_message_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->filter()
                ->all(),
        );

        return $dialogs
            ->map(function (Dialog $dialog) use ($messages): ?array {
                $messageId = (int) $dialog->getAttribute('latest_inbound_user_message_id');
                $message = $messages->get($messageId);

                if (! $message instanceof Message) {
                    return null;
                }

                return $this->formatItem($message, $dialog);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{id:int,sort_at:?string}
     */
    private function latestScopedInboundMessageBoundary(User $user, string $scope): array
    {
        $latestInboundUserMessageId = $this->latestInboundUserMessageIdFragment();

        $dialog = $this->orderedByLatestInbound(
            $this->withLatestInboundProjection($this->scopedDialogQuery($user, $scope)),
        )
            ->whereRaw(
                $latestInboundUserMessageId['sql'].' is not null',
                $latestInboundUserMessageId['bindings'],
            )
            ->first();

        return $this->boundaryFromDialog($dialog) ?? $this->emptyBoundary();
    }

    /**
     * @param  array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}  $preference
     * @return array{id:int,sort_at:?string}|null
     */
    private function notificationMessageBoundary(User $user, string $scope, array $preference, int $messageId): ?array
    {
        $latestInboundUserMessageId = $this->latestInboundUserMessageIdFragment();

        $dialog = $this->withLatestInboundProjection($this->notificationDialogQuery($user, $scope, $preference))
            ->whereRaw(
                $latestInboundUserMessageId['sql'].' = ?',
                [
                    ...$latestInboundUserMessageId['bindings'],
                    $messageId,
                ],
            )
            ->first();

        return $this->boundaryFromDialog($dialog);
    }

    /**
     * @param  array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}  $preference
     * @return array{id:int,sort_at:?string}|null
     */
    private function latestNotificationMessageBoundary(User $user, string $scope, array $preference, bool $includeDismissed = false): ?array
    {
        $dialog = $this->orderedByLatestInbound(
            $this->withLatestInboundProjection($this->notificationDialogQuery($user, $scope, $preference, includeDismissed: $includeDismissed)),
        )->first();

        return $this->boundaryFromDialog($dialog);
    }

    /**
     * @param  array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}  $preference
     */
    private function notificationDialogQuery(User $user, string $scope, array $preference, bool $includeDismissed = false): Builder
    {
        $query = $this->scopedDialogQuery($user, $scope);

        DialogResource::applyInboxStatusFilter($query, DialogInboxStatusData::CODE_REQUIRES_REPLY);

        $latestInboundUserMessageId = null;

        if ($preference['last_read_message_id'] > 0) {
            $latestInboundUserMessageId = $this->latestInboundUserMessageIdFragment();
            $latestInboundUserMessageSortAt = $this->latestInboundUserMessageSortAtFragment();

            if (filled($preference['last_read_message_sort_at'])) {
                $isAfterBoundary = $this->messageChronology->buildIsAfterCondition(
                    $latestInboundUserMessageSortAt,
                    $latestInboundUserMessageId,
                    ['sql' => '?', 'bindings' => [$preference['last_read_message_sort_at']]],
                    ['sql' => '?', 'bindings' => [$preference['last_read_message_id']]],
                );

                $query->whereRaw($isAfterBoundary['sql'], $isAfterBoundary['bindings']);

            } else {
                $query->whereRaw(
                    $latestInboundUserMessageId['sql'].' > ?',
                    [
                        ...$latestInboundUserMessageId['bindings'],
                        $preference['last_read_message_id'],
                    ],
                );
            }
        }

        if (! $includeDismissed && $preference['dismissed_message_ids'] !== []) {
            $latestInboundUserMessageId ??= $this->latestInboundUserMessageIdFragment();
            $placeholders = implode(', ', array_fill(0, count($preference['dismissed_message_ids']), '?'));

            $query->whereRaw(
                $latestInboundUserMessageId['sql'].' not in ('.$placeholders.')',
                [
                    ...$latestInboundUserMessageId['bindings'],
                    ...$preference['dismissed_message_ids'],
                ],
            );
        }

        return $query;
    }

    private function scopedDialogQuery(User $user, string $scope): Builder
    {
        return $this->applyDialogScope(
            Dialog::query()->with(['contact.assignedUser', 'channel']),
            $user,
            $scope,
        );
    }

    private function applyDialogScope(Builder $query, User $user, string $scope): Builder
    {
        return $query->whereHas('contact', function (Builder $query) use ($user, $scope): void {
            $query->whereNull('merged_into_contact_id');

            if ($scope === self::SCOPE_MINE) {
                $query->where('assigned_user_id', $user->id);

                return;
            }

            if ($scope !== self::SCOPE_ALL) {
                $query->where(function (Builder $query) use ($user): void {
                    $query
                        ->whereNull('assigned_user_id')
                        ->orWhere('assigned_user_id', $user->id);
                });
            }
        });
    }

    private function withLatestInboundProjection(Builder $query): Builder
    {
        $latestInboundUserMessageId = $this->latestInboundUserMessageIdFragment();
        $latestInboundUserMessageSortAt = $this->latestInboundUserMessageSortAtFragment();

        return $query
            ->select('dialogs.*')
            ->selectRaw(
                $latestInboundUserMessageId['sql'].' as latest_inbound_user_message_id',
                $latestInboundUserMessageId['bindings'],
            )
            ->selectRaw(
                $latestInboundUserMessageSortAt['sql'].' as latest_inbound_user_message_sort_at',
                $latestInboundUserMessageSortAt['bindings'],
            );
    }

    private function orderedByLatestInbound(Builder $query): Builder
    {
        $latestInboundUserMessageId = $this->latestInboundUserMessageIdFragment();
        $latestInboundUserMessageSortAt = $this->latestInboundUserMessageSortAtFragment();

        return $query
            ->orderByRaw(
                $latestInboundUserMessageSortAt['sql'].' desc',
                $latestInboundUserMessageSortAt['bindings'],
            )
            ->orderByRaw(
                $latestInboundUserMessageId['sql'].' desc',
                $latestInboundUserMessageId['bindings'],
            );
    }

    /**
     * @param  array<int, int>  $messageIds
     * @return EloquentCollection<int, Message>
     */
    private function messagesById(array $messageIds): EloquentCollection
    {
        if ($messageIds === []) {
            return new EloquentCollection;
        }

        return Message::query()
            ->with('channel')
            ->whereKey($messageIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array{sql:string,bindings:list<mixed>}
     */
    private function latestInboundUserMessageIdFragment(): array
    {
        return $this->messageChronology->latestDialogMessageIdFragment(Message::KIND_INBOUND_USER);
    }

    /**
     * @return array{sql:string,bindings:list<mixed>}
     */
    private function latestInboundUserMessageSortAtFragment(): array
    {
        return $this->messageChronology->latestDialogMessageSortAtFragment(Message::KIND_INBOUND_USER);
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
            'sort_at' => $this->normalizeSortAt($this->messageChronology->resolveSortAt($message)),
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
     * @return array{id:int,sort_at:?string}
     */
    private function emptyBoundary(): array
    {
        return [
            'id' => 0,
            'sort_at' => null,
        ];
    }

    /**
     * @return array{id:int,sort_at:?string}|null
     */
    private function boundaryFromDialog(?Dialog $dialog): ?array
    {
        if (! $dialog instanceof Dialog) {
            return null;
        }

        $messageId = (int) $dialog->getAttribute('latest_inbound_user_message_id');

        if ($messageId < 1) {
            return null;
        }

        return [
            'id' => $messageId,
            'sort_at' => $this->normalizeSortAt($dialog->getAttribute('latest_inbound_user_message_sort_at')),
        ];
    }

    /**
     * @param  array{id:int,sort_at:?string}  $boundary
     * @param  array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}  $preference
     */
    private function boundaryIsAfterPreference(array $boundary, array $preference): bool
    {
        if ($boundary['id'] < 1) {
            return false;
        }

        if (filled($boundary['sort_at']) && filled($preference['last_read_message_sort_at'])) {
            return $this->messageChronology->isAfter(
                $boundary['sort_at'],
                $boundary['id'],
                $preference['last_read_message_sort_at'],
                $preference['last_read_message_id'],
            );
        }

        return $boundary['id'] > $preference['last_read_message_id'];
    }

    private function normalizeSortAt(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return filled($value) ? (string) $value : null;
    }

    /**
     * @return array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}
     */
    private function resolvePreference(User $user): array
    {
        $rawPreference = $user->getTablePreference(self::PREFERENCE_KEY) ?? [];
        $defaultScope = $this->defaultScopeFor($user);

        return [
            'scope' => $this->normalizeScope($rawPreference['scope'] ?? null, $defaultScope),
            'last_read_message_id' => max(0, (int) ($rawPreference['last_read_message_id'] ?? 0)),
            'last_read_message_sort_at' => $this->normalizeSortAt($rawPreference['last_read_message_sort_at'] ?? null),
            'dismissed_message_ids' => $this->normalizeDismissedMessageIds($rawPreference['dismissed_message_ids'] ?? []),
        ];
    }

    /**
     * @param  array{scope:string,last_read_message_id:int,last_read_message_sort_at:?string,dismissed_message_ids:array<int, int>}  $preference
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
     * @return array<int, int>
     */
    private function normalizeDismissedMessageIds(mixed $messageIds): array
    {
        if (! is_array($messageIds)) {
            return [];
        }

        $normalized = [];

        foreach ($messageIds as $messageId) {
            $messageId = (int) $messageId;

            if ($messageId > 0 && ! in_array($messageId, $normalized, true)) {
                $normalized[] = $messageId;
            }
        }

        return array_slice($normalized, -self::DISMISSED_MESSAGE_LIMIT);
    }

    /**
     * @param  array<int, int>  $messageIds
     * @return array<int, int>
     */
    private function appendDismissedMessageId(array $messageIds, int $messageId): array
    {
        if ($messageId < 1) {
            return $this->normalizeDismissedMessageIds($messageIds);
        }

        $messageIds = array_values(array_filter(
            $this->normalizeDismissedMessageIds($messageIds),
            fn (int $existingMessageId): bool => $existingMessageId !== $messageId,
        ));
        $messageIds[] = $messageId;

        return array_slice($messageIds, -self::DISMISSED_MESSAGE_LIMIT);
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
