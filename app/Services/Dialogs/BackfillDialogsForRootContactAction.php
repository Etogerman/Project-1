<?php

namespace App\Services\Dialogs;

use App\Models\Contact;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillDialogsForRootContactAction
{
    public function __construct(
        private readonly InferHistoricalMessageSenderAction $inferHistoricalMessageSenderAction,
        private readonly ResolveDialogRoutePayloadAction $resolveDialogRoutePayloadAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @param  list<int>  $memberContactIds
     * @return array{
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }
     */
    public function handle(Contact $rootContact, array $memberContactIds, bool $apply): array
    {
        $messages = Message::query()
            ->with('contactIdentity')
            ->whereIn('contact_id', $memberContactIds)
            ->orderBy('channel_id')
            ->tap(fn ($query) => $this->messageChronology->applyLatestOrder($query))
            ->get();

        $identities = ContactIdentity::query()
            ->whereIn('contact_id', $memberContactIds)
            ->orderBy('channel_id')
            ->orderByDesc('id')
            ->get();

        $messagesByChannel = $messages->groupBy('channel_id');
        $identitiesByChannel = $identities->groupBy('channel_id');

        /** @var list<int> $channelIds */
        $channelIds = collect($messagesByChannel->keys())
            ->merge($identitiesByChannel->keys())
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->unique()
            ->sort()
            ->values()
            ->all();

        /** @var Collection<int, Channel> $channels */
        $channels = Channel::query()
            ->whereIn('id', $channelIds)
            ->get()
            ->keyBy('id');

        $stats = $this->emptyStats();

        if ($channelIds === []) {
            return $stats;
        }

        if (! $apply) {
            foreach ($channelIds as $channelId) {
                $this->analyzeChannel(
                    $rootContact,
                    $channelId,
                    $channels->get($channelId),
                    $messagesByChannel->get($channelId, collect()),
                    $identitiesByChannel->get($channelId, collect()),
                    $stats,
                    false,
                );
            }

            return $stats;
        }

        DB::transaction(function () use ($rootContact, $channelIds, $channels, $messagesByChannel, $identitiesByChannel, &$stats): void {
            foreach ($channelIds as $channelId) {
                $this->analyzeChannel(
                    $rootContact,
                    $channelId,
                    $channels->get($channelId),
                    $messagesByChannel->get($channelId, collect()),
                    $identitiesByChannel->get($channelId, collect()),
                    $stats,
                    true,
                );
            }
        });

        return $stats;
    }

    /**
     * @param  Collection<int, Message>  $channelMessages
     * @param  Collection<int, ContactIdentity>  $channelIdentities
     * @param  array{
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }  $stats
     */
    private function analyzeChannel(
        Contact $rootContact,
        int $channelId,
        ?Channel $channel,
        Collection $channelMessages,
        Collection $channelIdentities,
        array &$stats,
        bool $apply,
    ): void {
        $latestInbound = $channelMessages->first(
            fn (Message $message): bool => $message->direction === Message::DIRECTION_INBOUND
                && $channel instanceof Channel
                && $this->resolveDialogRoutePayloadAction->messageCanProvideRouteSource($message, $channel),
        );

        $fallbackIdentity = $channelIdentities->first();
        $routePayload = $latestInbound instanceof Message && $channel instanceof Channel
            ? $this->resolveDialogRoutePayloadAction->forInboundMessage($channel, $latestInbound)
            : ($fallbackIdentity instanceof ContactIdentity
                ? $this->resolveDialogRoutePayloadAction->forIdentityFallback($fallbackIdentity)
                : []);

        if ($channelMessages->isEmpty() && $channelIdentities->isNotEmpty()) {
            $stats['identity_only_dialogs_count']++;
        }

        if ($routePayload === []) {
            $stats['missing_route_source_count']++;
            $this->pushAnomalySample(
                $stats['anomaly_samples']['missing_route_source'],
                sprintf('contact:%d channel:%d', $rootContact->id, $channelId),
            );
        }

        $computedLastMessageAt = $this->resolveLatestMessageSortAt($channelMessages);
        $computedLastInboundAt = $this->resolveLatestMessageSortAt(
            $channelMessages,
            fn (Message $message): bool => $message->direction === Message::DIRECTION_INBOUND,
        );
        $computedLastOutboundAt = $this->resolveLatestMessageSortAt(
            $channelMessages,
            fn (Message $message): bool => $message->direction === Message::DIRECTION_OUTBOUND,
        );

        $dialog = Dialog::query()->firstOrNew([
            'contact_id' => $rootContact->id,
            'channel_id' => $channelId,
        ]);

        $wasExisting = $dialog->exists;

        $payload = [
            'last_message_at' => $this->maxDateTimeValue($dialog->last_message_at, $computedLastMessageAt),
            'last_inbound_at' => $this->maxDateTimeValue($dialog->last_inbound_at, $computedLastInboundAt),
            'last_outbound_at' => $this->maxDateTimeValue($dialog->last_outbound_at, $computedLastOutboundAt),
        ];
        $payload = array_merge($payload, $routePayload);

        $hasDialogChanges = $this->dialogNeedsUpdate($dialog, $payload);

        if (! $wasExisting) {
            $stats['dialogs_created']++;
        } elseif ($hasDialogChanges) {
            $stats['dialogs_updated']++;
        } else {
            $stats['dialogs_already_correct']++;
        }

        if ($apply && (! $wasExisting || $hasDialogChanges)) {
            $dialog->forceFill($payload)->save();
        } elseif (! $apply && ! $wasExisting) {
            $dialog->forceFill($payload);
        }

        if (! $apply) {
            $this->analyzeMessagesForChannel($dialog, $channelMessages, $stats, false);

            return;
        }

        $dialog->refresh();
        $this->analyzeMessagesForChannel($dialog, $channelMessages, $stats, true);
    }

    /**
     * @param  Collection<int, Message>  $channelMessages
     * @param  array{
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }  $stats
     */
    private function analyzeMessagesForChannel(Dialog $dialog, Collection $channelMessages, array &$stats, bool $apply): void
    {
        foreach ($channelMessages as $message) {
            $messagePayload = [];

            if ($message->dialog_id === null) {
                $stats['messages_linked']++;
                $messagePayload['dialog_id'] = $dialog->id;
            } elseif ((int) $message->dialog_id !== $dialog->id) {
                $stats['messages_relinked']++;
                $stats['relinked_dialog_mismatch_count']++;
                $messagePayload['dialog_id'] = $dialog->id;
                $this->pushAnomalySample(
                    $stats['anomaly_samples']['relinked_dialog_mismatch'],
                    sprintf('message:%d expected_dialog:%d actual_dialog:%d', $message->id, $dialog->id, (int) $message->dialog_id),
                );
            } else {
                $stats['messages_already_linked']++;
            }

            $senderPayload = $this->inferHistoricalMessageSenderAction->handle($message);

            if ($senderPayload === null) {
                $stats['messages_sender_already_present']++;
            } else {
                if ($senderPayload['is_unknown_kind']) {
                    $stats['unknown_message_kind_count']++;
                    $this->pushAnomalySample(
                        $stats['anomaly_samples']['unknown_message_kind'],
                        sprintf('message:%d kind:%s', $message->id, (string) $message->message_kind),
                    );
                }

                $stats['messages_sender_backfilled']++;
                unset($senderPayload['is_unknown_kind']);
                $messagePayload = array_merge($messagePayload, $senderPayload);
            }

            if ($apply && $messagePayload !== []) {
                $message->forceFill($messagePayload)->save();
            }
        }
    }

    private function dialogNeedsUpdate(Dialog $dialog, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            $currentValue = $dialog->getAttribute($key);

            if ($currentValue instanceof \DateTimeInterface) {
                $currentValue = $currentValue->format('Y-m-d H:i:s');
            }

            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d H:i:s');
            }

            if ($currentValue !== $value) {
                return true;
            }
        }

        return false;
    }

    private function maxDateTimeValue(mixed $currentValue, mixed $computedValue): mixed
    {
        if ($currentValue === null) {
            return $computedValue;
        }

        if ($computedValue === null) {
            return $currentValue;
        }

        $currentTimestamp = strtotime((string) $currentValue);
        $computedTimestamp = strtotime((string) $computedValue);

        return $computedTimestamp > $currentTimestamp ? $computedValue : $currentValue;
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLatestMessageSortAt(Collection $messages, ?Closure $filter = null): mixed
    {
        $message = $messages
            ->when(
                $filter instanceof Closure,
                fn (Collection $messages): Collection => $messages->filter($filter),
            )
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        return $message instanceof Message
            ? $this->messageChronology->resolveSortAt($message)
            : null;
    }

    /**
     * @param  list<string>  $samples
     */
    private function pushAnomalySample(array &$samples, string $value): void
    {
        if (count($samples) >= 10) {
            return;
        }

        $samples[] = $value;
    }

    /**
     * @return array{
     *   dialogs_created: int,
     *   dialogs_updated: int,
     *   dialogs_already_correct: int,
     *   messages_linked: int,
     *   messages_relinked: int,
     *   messages_already_linked: int,
     *   messages_sender_backfilled: int,
     *   messages_sender_already_present: int,
     *   unknown_message_kind_count: int,
     *   missing_route_source_count: int,
     *   identity_only_dialogs_count: int,
     *   relinked_dialog_mismatch_count: int,
     *   anomaly_samples: array{
     *     unknown_message_kind: list<string>,
     *     missing_route_source: list<string>,
     *     relinked_dialog_mismatch: list<string>,
     *   }
     * }
     */
    private function emptyStats(): array
    {
        return [
            'dialogs_created' => 0,
            'dialogs_updated' => 0,
            'dialogs_already_correct' => 0,
            'messages_linked' => 0,
            'messages_relinked' => 0,
            'messages_already_linked' => 0,
            'messages_sender_backfilled' => 0,
            'messages_sender_already_present' => 0,
            'unknown_message_kind_count' => 0,
            'missing_route_source_count' => 0,
            'identity_only_dialogs_count' => 0,
            'relinked_dialog_mismatch_count' => 0,
            'anomaly_samples' => [
                'unknown_message_kind' => [],
                'missing_route_source' => [],
                'relinked_dialog_mismatch' => [],
            ],
        ];
    }
}
