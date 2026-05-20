<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\ScenarioRun;
use DateTimeInterface;
use Illuminate\Support\Collection;

class ConsolidateDialogsForRootContactAction
{
    public function __construct(
        private readonly ResolveOrCreateDialogAction $resolveOrCreateDialogAction,
        private readonly ResolveDialogRoutePayloadAction $resolveDialogRoutePayloadAction,
        private readonly ResolveConsolidatedDialogStageAction $resolveConsolidatedDialogStageAction,
        private readonly CreateDialogStageHistoryMessageAction $createDialogStageHistoryMessageAction,
        private readonly MessageChronology $messageChronology,
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly BuildDialogMessageSnapshotPayloadAction $buildDialogMessageSnapshotPayloadAction,
    ) {}

    /**
     * @param  list<int>  $memberContactIds
     * @return array{
     *   dialogs_created: int,
     *   dialogs_reassigned: int,
     *   dialogs_updated: int,
     *   dialogs_merged: int,
     *   dialogs_deleted: int,
     *   messages_relinked: int,
     *   messages_null_linked: int,
     *   messages_contact_reassigned: int,
     * }
     */
    public function handle(
        Contact $rootContact,
        array $memberContactIds,
        bool $apply = true,
        bool $normalizeMessageContacts = false,
        bool $writeHistory = true,
    ): array {
        $memberContactIds = collect($memberContactIds)
            ->map(fn (mixed $contactId): int => (int) $contactId)
            ->prepend($rootContact->id)
            ->unique()
            ->sort()
            ->values()
            ->all();

        $dialogsQuery = Dialog::query()
            ->with(['channel', 'currentContactIdentity'])
            ->whereIn('contact_id', $memberContactIds)
            ->orderBy('id');

        $messagesQuery = Message::query()
            ->with('contactIdentity')
            ->whereIn('contact_id', $memberContactIds)
            ->orderBy('id');

        if ($apply) {
            $dialogsQuery->lockForUpdate();
            $messagesQuery->lockForUpdate();
        }

        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = $dialogsQuery->get();
        /** @var Collection<int, Message> $messages */
        $messages = $messagesQuery->get();

        $channelIds = $dialogs->pluck('channel_id')
            ->merge($messages->pluck('channel_id'))
            ->filter()
            ->map(fn (mixed $channelId): int => (int) $channelId)
            ->unique()
            ->sort()
            ->values();

        /** @var Collection<int, Channel> $channels */
        $channels = Channel::query()
            ->whereIn('id', $channelIds->all())
            ->get()
            ->keyBy('id');

        $stats = $this->emptyStats();

        foreach ($channelIds as $channelId) {
            /** @var Collection<int, Dialog> $dialogsInChannel */
            $dialogsInChannel = $dialogs
                ->where('channel_id', $channelId)
                ->values();
            /** @var Collection<int, Message> $messagesInChannel */
            $messagesInChannel = $messages
                ->where('channel_id', $channelId)
                ->values();

            if ($dialogsInChannel->isEmpty() && $messagesInChannel->isEmpty()) {
                continue;
            }

            $survivingDialog = $this->selectSurvivingDialog($dialogsInChannel, $rootContact->id);
            $dialogWasCreated = false;

            if (! $survivingDialog instanceof Dialog) {
                $stats['dialogs_created']++;

                if ($apply) {
                    $survivingDialog = $this->resolveOrCreateDialogAction->handle($rootContact, $channelId)
                        ->loadMissing(['channel', 'currentContactIdentity']);

                    $dialogWasCreated = true;
                    $dialogs->push($survivingDialog);
                    $dialogsInChannel = $dialogsInChannel->push($survivingDialog)->values();
                }
            }

            if (! $survivingDialog instanceof Dialog) {
                $stats['messages_null_linked'] += $messagesInChannel->whereNull('dialog_id')->count();
                $stats['messages_relinked'] += $messagesInChannel->whereNotNull('dialog_id')->count();
                $stats['messages_contact_reassigned'] += $normalizeMessageContacts
                    ? $messagesInChannel->reject(fn (Message $message): bool => (int) $message->contact_id === $rootContact->id)->count()
                    : 0;

                continue;
            }

            $payload = $this->buildDialogPayload(
                $rootContact,
                $survivingDialog,
                $dialogsInChannel,
                $messagesInChannel,
                $channels->get($channelId),
            );

            if ((int) $survivingDialog->contact_id !== $rootContact->id) {
                $stats['dialogs_reassigned']++;
            }

            $fromStage = $this->resolveHistoryFromStage($survivingDialog, $rootContact);
            $dialogNeedsUpdate = $this->dialogNeedsUpdate($survivingDialog, $payload);

            if (! $dialogWasCreated && $dialogNeedsUpdate) {
                $stats['dialogs_updated']++;
            }

            if ($apply && $dialogNeedsUpdate) {
                $survivingDialog->forceFill($payload)->save();
                $survivingDialog->refresh()->loadMissing(['channel', 'currentContactIdentity']);

                if ($writeHistory) {
                    $this->createDialogStageHistoryMessageAction->handle(
                        $survivingDialog,
                        $fromStage,
                        $payload['stage'] ?? null,
                        CreateDialogStageHistoryMessageAction::SOURCE_TYPE_SYSTEM,
                    );
                }
            }

            $messagesToRelink = $messagesInChannel
                ->reject(fn (Message $message): bool => (int) ($message->dialog_id ?? 0) === $survivingDialog->id)
                ->values();

            $stats['messages_null_linked'] += $messagesToRelink->whereNull('dialog_id')->count();
            $stats['messages_relinked'] += $messagesToRelink->whereNotNull('dialog_id')->count();

            if ($apply && $messagesToRelink->isNotEmpty()) {
                Message::query()
                    ->whereKey($messagesToRelink->modelKeys())
                    ->update([
                        'dialog_id' => $survivingDialog->id,
                        'updated_at' => now(),
                    ]);
            }

            if ($normalizeMessageContacts) {
                $messagesToReassign = $messagesInChannel
                    ->reject(fn (Message $message): bool => (int) $message->contact_id === $rootContact->id)
                    ->values();

                $stats['messages_contact_reassigned'] += $messagesToReassign->count();

                if ($apply && $messagesToReassign->isNotEmpty()) {
                    Message::query()
                        ->whereKey($messagesToReassign->modelKeys())
                        ->update([
                            'contact_id' => $rootContact->id,
                            'updated_at' => now(),
                        ]);
                }
            }

            $redundantDialogs = $dialogsInChannel
                ->reject(fn (Dialog $dialog): bool => $dialog->is($survivingDialog))
                ->values();

            if ($redundantDialogs->isNotEmpty()) {
                $this->relinkRedundantDialogScenarioRuns(
                    survivingDialog: $survivingDialog,
                    redundantDialogs: $redundantDialogs,
                    apply: $apply,
                );
            }

            $stats['dialogs_merged'] += $redundantDialogs->count();
            $stats['dialogs_deleted'] += $redundantDialogs->count();

            if ($apply && $redundantDialogs->isNotEmpty()) {
                Dialog::query()
                    ->whereKey($redundantDialogs->modelKeys())
                    ->delete();
            }
        }

        return $stats;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     */
    private function selectSurvivingDialog(Collection $dialogs, int $rootContactId): ?Dialog
    {
        /** @var ?Dialog $rootDialog */
        $rootDialog = $dialogs
            ->where('contact_id', $rootContactId)
            ->sortBy('id')
            ->first();

        if ($rootDialog instanceof Dialog) {
            return $rootDialog;
        }

        /** @var ?Dialog $fallbackDialog */
        $fallbackDialog = $dialogs
            ->sortBy('id')
            ->first();

        return $fallbackDialog;
    }

    /**
     * @param  Collection<int, Dialog>  $redundantDialogs
     */
    private function relinkRedundantDialogScenarioRuns(
        Dialog $survivingDialog,
        Collection $redundantDialogs,
        bool $apply,
    ): void {
        $redundantDialogIds = $redundantDialogs->modelKeys();

        if ($redundantDialogIds === []) {
            return;
        }

        $scenarioRunsQuery = ScenarioRun::query()
            ->whereIn('dialog_id', $redundantDialogIds)
            ->orderBy('id');

        if ($apply) {
            $scenarioRunsQuery->lockForUpdate();
        }

        /** @var Collection<int, ScenarioRun> $scenarioRuns */
        $scenarioRuns = $scenarioRunsQuery->get();

        if ($scenarioRuns->where('status', ScenarioRun::STATUS_ACTIVE)->isNotEmpty()) {
            throw new DialogConsolidationException('Cannot consolidate dialogs while a redundant dialog has an active scenario run.');
        }

        if (! $apply) {
            return;
        }

        $scenarioRunsToRelink = $scenarioRuns
            ->whereIn('status', [
                ScenarioRun::STATUS_COMPLETED,
                ScenarioRun::STATUS_CANCELLED,
                ScenarioRun::STATUS_FAILED,
            ])
            ->values();

        if ($scenarioRunsToRelink->isEmpty()) {
            return;
        }

        ScenarioRun::query()
            ->whereKey($scenarioRunsToRelink->modelKeys())
            ->update([
                'dialog_id' => $survivingDialog->id,
                'updated_at' => now(),
            ]);
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     * @return array<string, mixed>
     */
    private function buildDialogPayload(
        Contact $rootContact,
        Dialog $survivingDialog,
        Collection $dialogs,
        Collection $messages,
        ?Channel $channel,
    ): array {
        $payload = [
            'contact_id' => $rootContact->id,
            'channel_id' => $survivingDialog->channel_id,
            'last_message_at' => $this->resolveLastMessageAt($dialogs, $messages),
            'last_inbound_at' => $this->resolveLastInboundAt($dialogs, $messages),
            'last_outbound_at' => $this->resolveLastOutboundAt($dialogs, $messages),
            'pending_auto_reply_source_message_id' => $this->resolveLatestPendingAutoReplySourceMessage($dialogs, $messages)?->id,
        ];
        $payload = array_merge(
            $payload,
            $this->buildDialogMessageSnapshotPayloadAction->fromMessages($messages),
        );

        $routeSourceMessage = $this->resolveLatestInboundRouteSourceMessage($messages, $channel);

        if ($routeSourceMessage instanceof Message) {
            $payload = array_merge(
                $payload,
                $this->resolveDialogRoutePayloadAction->forInboundMessage($channel, $routeSourceMessage),
            );
        } else {
            $routeSourceDialog = $this->resolveFallbackRouteSourceDialog($dialogs);

            if ($routeSourceDialog instanceof Dialog && $channel instanceof Channel) {
                $payload = array_merge(
                    $payload,
                    $this->resolveDialogRoutePayloadAction->forDialogFallback($channel, $routeSourceDialog),
                );
            }
        }

        $phoneSourceDialog = $this->resolveLatestConfirmedPhoneDialog($dialogs, $survivingDialog);

        if ($phoneSourceDialog instanceof Dialog) {
            $payload['confirmed_phone_raw'] = $phoneSourceDialog->confirmed_phone_raw;
            $payload['confirmed_phone_normalized'] = $phoneSourceDialog->confirmed_phone_normalized;
            $payload['phone_confirmed_at'] = $phoneSourceDialog->phone_confirmed_at;
            $payload['phone_confirmed_via'] = $phoneSourceDialog->phone_confirmed_via;
        }

        $payload['stage'] = $this->resolveConsolidatedDialogStageAction->handle(
            rootContact: $rootContact,
            survivingDialog: $survivingDialog,
            dialogs: $dialogs,
            messages: $messages,
            phoneConfirmedAt: $payload['phone_confirmed_at'] ?? $survivingDialog->phone_confirmed_at,
        );

        return $payload;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLatestPendingAutoReplySourceMessage(Collection $dialogs, Collection $messages): ?Message
    {
        $pendingSourceIds = $dialogs
            ->pluck('pending_auto_reply_source_message_id')
            ->filter()
            ->map(fn (mixed $messageId): int => (int) $messageId)
            ->unique()
            ->values();

        if ($pendingSourceIds->isEmpty()) {
            return null;
        }

        /** @var ?Message $message */
        $message = $messages
            ->whereIn('id', $pendingSourceIds->all())
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $message->received_at,
                $message->id,
            ))
            ->first();

        return $message;
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLatestInboundRouteSourceMessage(Collection $messages, ?Channel $channel): ?Message
    {
        if (! $channel instanceof Channel) {
            return null;
        }

        /** @var ?Message $message */
        $message = $messages
            ->where('direction', Message::DIRECTION_INBOUND)
            ->filter(fn (Message $message): bool => $this->messageCanProvideRouteSource($message, $channel))
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        return $message;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     */
    private function resolveFallbackRouteSourceDialog(Collection $dialogs): ?Dialog
    {
        /** @var ?Dialog $dialog */
        $dialog = $dialogs
            ->filter(function (Dialog $dialog): bool {
                return filled($dialog->current_contact_identity_id) || filled($dialog->external_chat_id);
            })
            ->sortByDesc(fn (Dialog $dialog): string => $this->dialogRouteSortKey($dialog))
            ->first();

        return $dialog;
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     */
    private function resolveLatestConfirmedPhoneDialog(Collection $dialogs, Dialog $survivingDialog): ?Dialog
    {
        $dialogsWithPhone = $dialogs->filter(fn (Dialog $dialog): bool => $dialog->phone_confirmed_at !== null);

        if ($dialogsWithPhone->isEmpty()) {
            return null;
        }

        $latestTimestamp = $dialogsWithPhone
            ->map(fn (Dialog $dialog): string => $this->messageChronology->timestampSortKey($dialog->phone_confirmed_at))
            ->sortDesc()
            ->first();

        $latestDialogs = $dialogsWithPhone
            ->filter(fn (Dialog $dialog): bool => $this->messageChronology->timestampSortKey($dialog->phone_confirmed_at) === $latestTimestamp)
            ->values();

        /** @var ?Dialog $survivingDialogMatch */
        $survivingDialogMatch = $latestDialogs->first(fn (Dialog $dialog): bool => $dialog->is($survivingDialog));

        if ($survivingDialogMatch instanceof Dialog) {
            return $survivingDialogMatch;
        }

        /** @var ?Dialog $fallbackDialog */
        $fallbackDialog = $latestDialogs
            ->sortBy('id')
            ->first();

        return $fallbackDialog;
    }

    private function resolveHistoryFromStage(Dialog $dialog, Contact $rootContact): string
    {
        return $this->resolveDialogStageAction->handle($dialog, $rootContact);
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLastMessageAt(Collection $dialogs, Collection $messages): mixed
    {
        /** @var ?Message $message */
        $message = $messages
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        if ($message instanceof Message) {
            return $this->messageChronology->resolveSortAt($message);
        }

        return $this->maxDialogTimestamp($dialogs, 'last_message_at');
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLastInboundAt(Collection $dialogs, Collection $messages): mixed
    {
        /** @var ?Message $message */
        $message = $messages
            ->where('direction', Message::DIRECTION_INBOUND)
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        if ($message instanceof Message) {
            return $this->messageChronology->resolveSortAt($message);
        }

        return $this->maxDialogTimestamp($dialogs, 'last_inbound_at');
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     * @param  Collection<int, Message>  $messages
     */
    private function resolveLastOutboundAt(Collection $dialogs, Collection $messages): mixed
    {
        /** @var ?Message $message */
        $message = $messages
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->sortByDesc(fn (Message $message): string => $this->messageChronology->timestampAndIdSortKey(
                $this->messageChronology->resolveSortAt($message),
                $message->id,
            ))
            ->first();

        if ($message instanceof Message) {
            return $this->messageChronology->resolveSortAt($message);
        }

        return $this->maxDialogTimestamp($dialogs, 'last_outbound_at');
    }

    /**
     * @param  Collection<int, Dialog>  $dialogs
     */
    private function maxDialogTimestamp(Collection $dialogs, string $field): mixed
    {
        /** @var ?Dialog $dialog */
        $dialog = $dialogs
            ->filter(fn (Dialog $dialog): bool => $dialog->getAttribute($field) !== null)
            ->sortByDesc(fn (Dialog $dialog): string => $this->messageChronology->timestampAndIdSortKey($dialog->getAttribute($field), $dialog->id))
            ->first();

        return $dialog?->getAttribute($field);
    }

    private function messageCanProvideRouteSource(Message $message, Channel $channel): bool
    {
        return $this->resolveDialogRoutePayloadAction->messageCanProvideRouteSource($message, $channel);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dialogNeedsUpdate(Dialog $dialog, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($this->normalizeComparableValue($dialog->getAttribute($key)) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    private function dialogRouteSortKey(Dialog $dialog): string
    {
        return $this->messageChronology->timestampSortKey($dialog->last_inbound_at)
            .'|'.$this->messageChronology->timestampSortKey($dialog->last_message_at)
            .'|'.str_pad((string) $dialog->id, 10, '0', STR_PAD_LEFT);
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }

    /**
     * @return array{
     *   dialogs_created: int,
     *   dialogs_reassigned: int,
     *   dialogs_updated: int,
     *   dialogs_merged: int,
     *   dialogs_deleted: int,
     *   messages_relinked: int,
     *   messages_null_linked: int,
     *   messages_contact_reassigned: int,
     * }
     */
    private function emptyStats(): array
    {
        return [
            'dialogs_created' => 0,
            'dialogs_reassigned' => 0,
            'dialogs_updated' => 0,
            'dialogs_merged' => 0,
            'dialogs_deleted' => 0,
            'messages_relinked' => 0,
            'messages_null_linked' => 0,
            'messages_contact_reassigned' => 0,
        ];
    }
}
