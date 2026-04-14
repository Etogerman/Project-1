<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\IsDialogReadyForBitrix24LiveBridgeAction;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\AssignContactStartTagAction;
use App\Services\Contacts\BrokenContactMergeChainException;
use App\Services\Contacts\ContactMergeException;
use App\Services\Contacts\CreateContactDuplicateReviewAction;
use App\Services\Contacts\FindDuplicateContactRootsByPhoneAction;
use App\Services\Contacts\MergeContactsAction;
use App\Services\Contacts\ApplyContactFirstNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Bitrix24\QueueBitrix24LiveMessageExportAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Dialogs\DialogConsolidationException;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageAction
{
    public function __construct(
        protected AddContactPhoneAction $addContactPhoneAction,
        protected AssignContactStartTagAction $assignContactStartTagAction,
        protected FindDuplicateContactRootsByPhoneAction $findDuplicateContactRootsByPhoneAction,
        protected CreateContactDuplicateReviewAction $createContactDuplicateReviewAction,
        protected MergeContactsAction $mergeContactsAction,
        protected ApplyContactFirstNameAction $applyContactFirstNameAction,
        protected ResolveRootContactAction $resolveRootContactAction,
        protected ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        protected ChannelActivityLogger $channelActivityLogger,
        protected IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
        protected QueueBitrix24LiveMessageExportAction $queueBitrix24LiveMessageExportAction,
        protected SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        protected SyncDialogConfirmedPhoneAction $syncDialogConfirmedPhoneAction,
    ) {}

    public function handle(Channel $channel, IncomingBotMessage $message): ?StoredInboundMessageResult
    {
        return DB::transaction(function () use ($channel, $message): ?StoredInboundMessageResult {
            if ($this->isInboundSystemEvent($message)) {
                return $this->storeInboundSystemEvent($channel, $message);
            }

            $identity = $this->findContactIdentityForChannel($channel, $message->externalUserId)
                ?? $this->resolveOrCreateContactIdentity($channel, $message);

            $contact = $identity->contact;

            if (filled($message->providerEventKey)) {
                $existingMessage = $this->findExistingInboundMessage($channel, $message->providerEventKey);

                if ($existingMessage !== null) {
                    $existingMessage->loadMissing(['contact', 'contactIdentity']);
                    $phoneCaptureStatus = $this->captureSharedPhoneIfNeeded($channel, $contact, $existingMessage, $message);

                    if ($phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                        $existingMessage->refresh()->load(['contact', 'contactIdentity']);
                    }

                    $this->syncStoredInboundMessageMetadata($channel, $contact, $existingMessage, $message);
                    $this->syncDialogConfirmedPhoneIfNeeded($existingMessage, $message, $phoneCaptureStatus);
                    $this->assignStartTagIfNeeded($channel, $contact, $existingMessage);
                    $this->syncDialogPendingAutoReplySource($existingMessage, $contact);
                    $this->queueBitrix24LiveMessageExportAction->handle($existingMessage);

                    return new StoredInboundMessageResult(
                        message: $existingMessage,
                        phoneCaptureStatus: $phoneCaptureStatus,
                    );
                }
            }

            if ($this->shouldSyncInboundIdentityProfile($identity, $message)) {
                $this->syncInboundIdentityProfile($identity, $message);
            }

            if (
                $contact instanceof Contact
                && filled($message->contactName)
                && $this->shouldApplyAutoFirstName($contact, $message)
            ) {
                $this->applyContactFirstNameAction->handle(
                    $contact,
                    $message->contactName,
                    Contact::FIRST_NAME_SOURCE_AUTO,
                    ApplyContactFirstNameAction::REASON_AUTO_INBOUND,
                );
            }

            try {
                $storedMessage = Message::query()->create([
                    'contact_id' => $identity->contact_id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_INBOUND,
                    'message_kind' => $this->resolveInboundMessageKind($message),
                    'provider_event_key' => $message->providerEventKey,
                    'external_chat_id' => $message->externalChatId,
                    'external_message_id' => $message->externalMessageId,
                    'text' => $message->text,
                    'message_parameter' => $message->messageParameter,
                    'raw_payload' => $message->rawPayload,
                    'received_at' => $message->receivedAt,
                ]);

                $phoneCaptureStatus = $this->captureSharedPhoneIfNeeded($channel, $contact, $storedMessage, $message);

                if ($phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                    $storedMessage->refresh()->load(['contact', 'contactIdentity']);
                }

                $this->syncStoredInboundMessageMetadata($channel, $contact, $storedMessage, $message);
                $this->syncDialogConfirmedPhoneIfNeeded($storedMessage, $message, $phoneCaptureStatus);
                $this->assignStartTagIfNeeded($channel, $contact, $storedMessage);
                $this->syncDialogPendingAutoReplySource($storedMessage, $contact);
                $this->queueBitrix24LiveMessageExportAction->handle($storedMessage);

                return new StoredInboundMessageResult(
                    message: $storedMessage,
                    phoneCaptureStatus: $phoneCaptureStatus,
                );
            } catch (QueryException $exception) {
                if (! filled($message->providerEventKey) || ! $this->wasUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $existingMessage = $this->findExistingInboundMessage($channel, $message->providerEventKey) ?? throw $exception;
                $existingMessage->loadMissing(['contact', 'contactIdentity']);
                $phoneCaptureStatus = $this->captureSharedPhoneIfNeeded($channel, $contact, $existingMessage, $message);

                if ($phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                    $existingMessage->refresh()->load(['contact', 'contactIdentity']);
                }

                $this->syncStoredInboundMessageMetadata($channel, $contact, $existingMessage, $message);
                $this->syncDialogConfirmedPhoneIfNeeded($existingMessage, $message, $phoneCaptureStatus);
                $this->assignStartTagIfNeeded($channel, $contact, $existingMessage);
                $this->syncDialogPendingAutoReplySource($existingMessage, $contact);
                $this->queueBitrix24LiveMessageExportAction->handle($existingMessage);

                return new StoredInboundMessageResult(
                    message: $existingMessage,
                    phoneCaptureStatus: $phoneCaptureStatus,
                );
            }
        });
    }

    protected function storeInboundSystemEvent(Channel $channel, IncomingBotMessage $message): ?StoredInboundMessageResult
    {
        $identity = $this->findContactIdentityForChannel($channel, $message->externalUserId);

        if (! $identity instanceof ContactIdentity) {
            $this->logIgnoredInboundSystemEvent($channel, $message, 'identity_missing');

            return null;
        }

        $dialog = $this->findExistingDialogForIdentity($channel, $identity);

        if (! $dialog instanceof Dialog) {
            $this->logIgnoredInboundSystemEvent($channel, $message, 'dialog_missing');

            return null;
        }

        if (filled($message->providerEventKey)) {
            $existingMessage = $this->findExistingInboundMessage($channel, $message->providerEventKey);

            if ($existingMessage !== null) {
                $existingMessage->loadMissing(['contact', 'contactIdentity', 'dialog']);

                $this->syncStoredInboundMessageMetadata($channel, $identity->contact, $existingMessage, $message);
                $this->syncDialogBotSubscriptionState($existingMessage);

                return new StoredInboundMessageResult(message: $existingMessage);
            }
        }

        try {
            $storedMessage = Message::query()->create([
                'contact_id' => $identity->contact_id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_INBOUND,
                'message_kind' => $this->resolveInboundMessageKind($message),
                'system_event_code' => $this->resolveInboundSystemEventCode($message),
                'provider_event_key' => $message->providerEventKey,
                'external_chat_id' => $message->externalChatId,
                'external_message_id' => $message->externalMessageId,
                'text' => $message->text,
                'message_parameter' => $message->messageParameter,
                'raw_payload' => $message->rawPayload,
                'received_at' => $message->receivedAt,
            ]);

            $this->syncStoredInboundMessageMetadata($channel, $identity->contact, $storedMessage, $message);
            $this->syncDialogBotSubscriptionState($storedMessage);

            return new StoredInboundMessageResult(message: $storedMessage);
        } catch (QueryException $exception) {
            if (! filled($message->providerEventKey) || ! $this->wasUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existingMessage = $this->findExistingInboundMessage($channel, $message->providerEventKey) ?? throw $exception;
            $existingMessage->loadMissing(['contact', 'contactIdentity', 'dialog']);

            $this->syncStoredInboundMessageMetadata($channel, $identity->contact, $existingMessage, $message);
            $this->syncDialogBotSubscriptionState($existingMessage);

            return new StoredInboundMessageResult(message: $existingMessage);
        }
    }

    protected function findContactIdentityForChannel(Channel $channel, ?string $externalUserId): ?ContactIdentity
    {
        return ContactIdentity::query()
            ->with('contact')
            ->where('channel_id', $channel->id)
            ->where('external_user_id', $externalUserId)
            ->first();
    }

    protected function resolveOrCreateContactIdentity(Channel $channel, IncomingBotMessage $message): ContactIdentity
    {
        if (! filled($message->externalUserId)) {
            return $this->createNewContactWithIdentity($channel, $message);
        }

        $this->acquireCrossChannelIdentityLock($channel->platform, $message->externalUserId);

        $identity = $this->findContactIdentityForChannel($channel, $message->externalUserId);

        if ($identity instanceof ContactIdentity) {
            return $identity;
        }

        $identity = $this->resolveCrossChannelContactIdentity($channel, $message);

        if ($identity instanceof ContactIdentity) {
            return $identity;
        }

        return $this->createNewContactWithIdentity($channel, $message);
    }

    protected function resolveCrossChannelContactIdentity(Channel $channel, IncomingBotMessage $message): ?ContactIdentity
    {
        $matchedIdentities = ContactIdentity::query()
            ->with('contact')
            ->where('platform', $channel->platform)
            ->where('external_user_id', $message->externalUserId)
            ->orderBy('id')
            ->get();

        if ($matchedIdentities->isEmpty()) {
            return null;
        }

        $matchedIdentityIds = $matchedIdentities->pluck('id')->all();
        $rootContactsById = [];

        foreach ($matchedIdentities as $matchedIdentity) {
            try {
                $rootContact = $this->resolveRootContactAction->handle($matchedIdentity->contact_id);
            } catch (BrokenContactMergeChainException $exception) {
                $this->channelActivityLogger->warning(
                    $channel,
                    'contact.cross_channel_identity_broken_merge_chain',
                    'Cross-channel identity не удалось безопасно привязать из-за сломанной merge chain.',
                    [
                        'channel_id' => $channel->id,
                        'platform' => $channel->platform,
                        'external_user_id' => $message->externalUserId,
                        'matched_identity_id' => $matchedIdentity->id,
                        'matched_channel_id' => $matchedIdentity->channel_id,
                        'error' => $exception->getMessage(),
                    ],
                );

                return null;
            }

            $rootContactsById[$rootContact->id] = $rootContact;
        }

        $matchedRootContactIds = array_keys($rootContactsById);
        sort($matchedRootContactIds);

        if (count($matchedRootContactIds) !== 1) {
            $this->channelActivityLogger->warning(
                $channel,
                'contact.cross_channel_identity_ambiguous',
                'Cross-channel identity не привязана автоматически: найдено несколько root-контактов.',
                [
                    'channel_id' => $channel->id,
                    'platform' => $channel->platform,
                    'external_user_id' => $message->externalUserId,
                    'matched_identity_ids' => $matchedIdentityIds,
                    'matched_root_contact_ids' => $matchedRootContactIds,
                    'matched_root_count' => count($matchedRootContactIds),
                ],
            );

            return null;
        }

        /** @var Contact $rootContact */
        $rootContact = reset($rootContactsById);
        /** @var ContactIdentity $matchedIdentity */
        $matchedIdentity = $matchedIdentities->first();

        try {
            $identity = $this->createContactIdentityForChannel($rootContact, $channel, $message);
        } catch (QueryException $exception) {
            if (! $this->wasUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $identity = $this->findContactIdentityForChannel($channel, $message->externalUserId) ?? throw $exception;
        }

        $this->channelActivityLogger->info(
            $channel,
            'contact.cross_channel_identity_linked',
            'Новый канал привязан к существующему контакту по platform user ID.',
            [
                'contact_id' => $rootContact->id,
                'channel_id' => $channel->id,
                'matched_identity_id' => $matchedIdentity->id,
                'matched_channel_id' => $matchedIdentity->channel_id,
                'platform' => $channel->platform,
                'external_user_id' => $message->externalUserId,
            ],
        );

        return $identity;
    }

    protected function createContactIdentityForChannel(Contact $contact, Channel $channel, IncomingBotMessage $message): ContactIdentity
    {
        return ContactIdentity::query()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $message->platform,
            'external_user_id' => $message->externalUserId,
            'display_name' => $message->contactName,
            'external_username' => $message->externalUsername,
        ])->load('contact');
    }

    protected function createNewContactWithIdentity(Channel $channel, IncomingBotMessage $message): ContactIdentity
    {
        $contact = Contact::query()->create([]);

        try {
            return $this->createContactIdentityForChannel($contact, $channel, $message);
        } catch (QueryException $exception) {
            if (! $this->wasUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $contact->delete();

            return $this->findContactIdentityForChannel($channel, $message->externalUserId) ?? throw $exception;
        }
    }

    protected function findExistingDialogForIdentity(Channel $channel, ContactIdentity $identity): ?Dialog
    {
        return Dialog::query()
            ->where('contact_id', $identity->contact_id)
            ->where('channel_id', $channel->id)
            ->first();
    }

    protected function findExistingInboundMessage(Channel $channel, string $providerEventKey): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('provider_event_key', $providerEventKey)
            ->first();
    }

    protected function logIgnoredInboundSystemEvent(
        Channel $channel,
        IncomingBotMessage $message,
        string $reason,
    ): void {
        $this->channelActivityLogger->info(
            $channel,
            'contact.telegram_unsubscribe_ignored',
            'Telegram unsubscribe system event проигнорирован: не найден действующий route context.',
            [
                'channel_id' => $channel->id,
                'reason' => $reason,
                'provider_event_key' => $message->providerEventKey,
                'external_user_id' => $message->externalUserId,
                'external_chat_id' => $message->externalChatId,
                'system_event_code' => $message->systemEventCode,
            ],
        );
    }

    protected function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }

    protected function syncInboundIdentityProfile(ContactIdentity $identity, IncomingBotMessage $message): void
    {
        $updates = [];

        if (filled($message->contactName) && $identity->display_name !== $message->contactName) {
            $updates['display_name'] = $message->contactName;
        }

        if (
            filled($message->externalUsername)
            && $identity->external_username !== $message->externalUsername
        ) {
            $updates['external_username'] = $message->externalUsername;
        }

        if ($updates === []) {
            return;
        }

        $identity->forceFill($updates)->save();
    }

    protected function shouldSyncInboundIdentityProfile(ContactIdentity $identity, IncomingBotMessage $message): bool
    {
        $latestInbound = Message::query()
            ->where('contact_identity_id', $identity->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestInbound instanceof Message) {
            return true;
        }

        return $this->isSameOrNewerInboundProfileCandidate($message, $latestInbound);
    }

    protected function shouldApplyAutoFirstName(Contact $contact, IncomingBotMessage $message): bool
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        $latestInbound = Message::query()
            ->where('contact_id', $rootContact->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        if (! $latestInbound instanceof Message) {
            return true;
        }

        return $this->isSameOrNewerInboundProfileCandidate($message, $latestInbound);
    }

    protected function isSameOrNewerInboundProfileCandidate(
        IncomingBotMessage $candidate,
        Message $current,
    ): bool {
        $candidateReceivedAt = $candidate->receivedAt;
        $currentReceivedAt = $current->received_at;

        if ($candidateReceivedAt !== null && $currentReceivedAt !== null) {
            if ($candidateReceivedAt->gt($currentReceivedAt)) {
                return true;
            }

            if ($candidateReceivedAt->lt($currentReceivedAt)) {
                return false;
            }

            return $this->isSameOrNewerInboundProfileTieBreakCandidate($candidate, $current);
        } elseif ($candidateReceivedAt !== null) {
            return true;
        } elseif ($currentReceivedAt !== null) {
            return false;
        }

        return true;
    }

    protected function isSameOrNewerInboundProfileTieBreakCandidate(
        IncomingBotMessage $candidate,
        Message $current,
    ): bool {
        $candidateSequence = $this->normalizeInboundProfileSequence($candidate->providerEventKey);
        $currentSequence = $this->normalizeInboundProfileSequence($current->provider_event_key);

        if ($candidateSequence !== null && $currentSequence !== null) {
            return $candidateSequence >= $currentSequence;
        }

        return false;
    }

    protected function normalizeInboundProfileSequence(?string $value): ?int
    {
        if (! filled($value) || ! ctype_digit($value)) {
            return null;
        }

        return (int) $value;
    }

    protected function isInboundSystemEvent(IncomingBotMessage $message): bool
    {
        return $message->inboundKind === IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT;
    }

    protected function resolveInboundMessageKind(IncomingBotMessage $message): string
    {
        if ($message->inboundKind === IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT) {
            return Message::KIND_INBOUND_SYSTEM_EVENT;
        }

        return $message->inboundKind === IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
            ? Message::KIND_INBOUND_CONTACT_SHARE
            : Message::KIND_INBOUND_USER;
    }

    protected function resolveInboundSystemEventCode(IncomingBotMessage $message): ?string
    {
        return match ($message->systemEventCode) {
            IncomingBotMessage::SYSTEM_EVENT_BOT_BLOCKED_BY_USER => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            IncomingBotMessage::SYSTEM_EVENT_BOT_UNBLOCKED_BY_USER => Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER,
            default => null,
        };
    }

    protected function syncStoredInboundMessageMetadata(
        Channel $channel,
        ?Contact $fallbackContact,
        Message $storedMessage,
        IncomingBotMessage $message,
    ): void {
        $contact = $storedMessage->contact ?? $fallbackContact;

        if (! $contact instanceof Contact) {
            return;
        }

        $this->syncMessageDialogMetadataAction->handle(
            $storedMessage,
            $contact,
            $channel,
            $storedMessage->contactIdentity,
            $storedMessage->external_chat_id ?? $message->externalChatId,
            $this->resolveInboundSentByType($message),
            sentBySystemCode: $this->resolveInboundSentBySystemCode($message),
        );
    }

    protected function resolveInboundSentByType(IncomingBotMessage $message): string
    {
        return $message->inboundKind === IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT
            ? Message::SENT_BY_TYPE_SYSTEM
            : Message::SENT_BY_TYPE_CONTACT;
    }

    protected function resolveInboundSentBySystemCode(IncomingBotMessage $message): ?string
    {
        return $message->inboundKind === IncomingBotMessage::KIND_INBOUND_SYSTEM_EVENT
            ? Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION
            : null;
    }

    protected function syncDialogBotSubscriptionState(Message $storedMessage): void
    {
        if ($storedMessage->message_kind !== Message::KIND_INBOUND_SYSTEM_EVENT) {
            return;
        }

        $storedMessage->loadMissing('dialog');

        $dialog = $storedMessage->dialog;

        if (! $dialog instanceof Dialog) {
            return;
        }

        $lockedDialog = Dialog::query()
            ->whereKey($dialog->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedDialog instanceof Dialog) {
            return;
        }

        if (! $this->isSameOrNewerDialogSubscriptionCandidate($storedMessage, $lockedDialog)) {
            return;
        }

        $payload = [
            'bot_subscription_status' => $this->resolveDialogBotSubscriptionStatus($storedMessage),
            'bot_subscription_changed_at' => $storedMessage->received_at,
            'bot_subscription_source_message_id' => $storedMessage->id,
        ];

        if (
            $lockedDialog->bot_subscription_status === $payload['bot_subscription_status']
            && $this->normalizeComparableDateTime($lockedDialog->bot_subscription_changed_at) === $this->normalizeComparableDateTime($payload['bot_subscription_changed_at'])
            && $lockedDialog->bot_subscription_source_message_id === $payload['bot_subscription_source_message_id']
        ) {
            return;
        }

        $lockedDialog->forceFill($payload)->save();
    }

    protected function resolveDialogBotSubscriptionStatus(Message $storedMessage): ?string
    {
        return $storedMessage->system_event_code === Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER
            ? Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER
            : null;
    }

    protected function isSameOrNewerDialogSubscriptionCandidate(
        Message $candidate,
        Dialog $currentDialog,
    ): bool {
        $candidateReceivedAt = $candidate->received_at;
        $currentChangedAt = $currentDialog->bot_subscription_changed_at;

        if ($candidateReceivedAt !== null && $currentChangedAt !== null) {
            if ($candidateReceivedAt->gt($currentChangedAt)) {
                return true;
            }

            if ($candidateReceivedAt->lt($currentChangedAt)) {
                return false;
            }
        } elseif ($candidateReceivedAt !== null) {
            return true;
        } elseif ($currentChangedAt !== null) {
            return false;
        }

        if ($currentDialog->bot_subscription_source_message_id !== null) {
            return $candidate->id >= $currentDialog->bot_subscription_source_message_id;
        }

        return true;
    }

    protected function normalizeComparableDateTime(mixed $value): ?string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d H:i:s')
            : null;
    }

    protected function syncDialogConfirmedPhoneIfNeeded(
        Message $storedMessage,
        IncomingBotMessage $message,
        string $phoneCaptureStatus,
    ): void {
        if (! in_array($phoneCaptureStatus, [
            StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW,
            StoredInboundMessageResult::PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT,
            StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT,
            StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING,
        ], true)) {
            return;
        }

        if (! filled($message->sharedPhoneNumber)) {
            return;
        }

        $phoneNormalized = AddContactPhoneAction::normalizePhone($message->sharedPhoneNumber);

        if ($phoneNormalized === '') {
            return;
        }

        $this->syncDialogConfirmedPhoneAction->handle(
            $storedMessage,
            $message->sharedPhoneNumber,
            $phoneNormalized,
        );
    }

    protected function assignStartTagIfNeeded(
        Channel $channel,
        ?Contact $fallbackContact,
        Message $storedMessage,
    ): void {
        $contact = $storedMessage->contact ?? $fallbackContact;

        if (! $contact instanceof Contact) {
            return;
        }

        $this->assignContactStartTagAction->handle($contact, $storedMessage, $channel);
    }

    protected function syncDialogPendingAutoReplySource(
        Message $storedMessage,
        ?Contact $fallbackContact,
    ): void {
        if (
            $storedMessage->direction !== Message::DIRECTION_INBOUND
            || $storedMessage->message_kind !== Message::KIND_INBOUND_USER
            || ! filled($storedMessage->message_parameter)
        ) {
            return;
        }

        $storedMessage->loadMissing(['dialog', 'contact']);

        $dialog = $storedMessage->dialog;
        $contact = $storedMessage->contact ?? $fallbackContact;

        if (! $dialog instanceof Dialog || ! $contact instanceof Contact) {
            return;
        }

        $existingPendingSource = null;

        if (filled($dialog->pending_auto_reply_source_message_id)) {
            $existingPendingSource = Message::query()->find($dialog->pending_auto_reply_source_message_id);

            if (
                $existingPendingSource instanceof Message
                && ! $this->isSameOrNewerPendingSourceCandidate($storedMessage, $existingPendingSource)
            ) {
                return;
            }
        }

        if (! $contact->isAutoReplyEnabled() || $storedMessage->hasSuccessfulAutoReply()) {
            $this->clearDialogPendingAutoReplySource($dialog);

            return;
        }

        $rootContact = $this->resolveRootContactAction->handle($contact);

        if ($this->canUseImmediateFinalParameterPath($dialog, $rootContact)) {
            $this->clearDialogPendingAutoReplySource($dialog);

            return;
        }

        if ($dialog->pending_auto_reply_source_message_id === $storedMessage->id) {
            return;
        }

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => $storedMessage->id,
        ])->save();
    }

    protected function canUseImmediateFinalParameterPath(Dialog $dialog, Contact $rootContact): bool
    {
        if (! $rootContact->phoneNumbers()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->exists()) {
            return false;
        }

        if ($this->resolveNextDataCollectionFieldAction->handle($rootContact) !== null) {
            return false;
        }

        return $this->isDialogReadyForBitrix24LiveBridgeAction->handle($dialog);
    }

    protected function isSameOrNewerPendingSourceCandidate(
        Message $candidate,
        Message $current,
    ): bool {
        $candidateReceivedAt = $candidate->received_at;
        $currentReceivedAt = $current->received_at;

        if ($candidateReceivedAt !== null && $currentReceivedAt !== null) {
            if ($candidateReceivedAt->gt($currentReceivedAt)) {
                return true;
            }

            if ($candidateReceivedAt->lt($currentReceivedAt)) {
                return false;
            }
        } elseif ($candidateReceivedAt !== null) {
            return true;
        } elseif ($currentReceivedAt !== null) {
            return false;
        }

        return $candidate->id >= $current->id;
    }

    protected function clearDialogPendingAutoReplySource(Dialog $dialog): void
    {
        if ($dialog->pending_auto_reply_source_message_id === null) {
            return;
        }

        $dialog->forceFill([
            'pending_auto_reply_source_message_id' => null,
        ])->save();
    }

    protected function captureSharedPhoneIfNeeded(
        Channel $channel,
        ?Contact $contact,
        Message $storedMessage,
        IncomingBotMessage $message,
    ): string {
        if (
            $contact === null
            || $message->inboundKind !== IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
        ) {
            return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_NOT_APPLICABLE;
        }

        if (! filled($message->sharedPhoneNumber)) {
            if ($channel->platform === Channel::PLATFORM_MAX) {
                $this->channelActivityLogger->info(
                    $channel,
                    'max.contact_share_unknown_format',
                    'Контакт из MAX получен, но номер не удалось извлечь из payload.',
                    [
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'message_id' => $storedMessage->id,
                        'payload_keys_preview' => $this->extractPayloadKeysPreview($message->rawPayload),
                    ],
                );
            }

            return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_UNKNOWN_FORMAT;
        }

        if (
            filled($message->sharedContactUserId)
            && $message->sharedContactUserId !== $message->externalUserId
        ) {
            $this->channelActivityLogger->info(
                $channel,
                'contact.phone_capture_skipped_sender_mismatch',
                'Номер телефона не сохранён: contact.user_id не совпадает с отправителем.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $storedMessage->id,
                    'sender_user_id' => $message->externalUserId,
                    'shared_contact_user_id' => $message->sharedContactUserId,
                ],
            );

            return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_SENDER_MISMATCH;
        }

        $phoneNumber = $this->addContactPhoneAction->handle(
            $contact,
            $message->sharedPhoneNumber,
            $channel->platform === Channel::PLATFORM_MAX
                ? ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE
                : ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
        );

        if ($phoneNumber->wasRecentlyCreated) {
            $this->channelActivityLogger->info(
                $channel,
                'contact.phone_captured',
                'Номер телефона сохранён у контакта.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $storedMessage->id,
                    'source' => $phoneNumber->source,
                    'phone_last4' => mb_substr($phoneNumber->phone_normalized, -4),
                    'phone_masked' => AddContactPhoneAction::maskPhone($phoneNumber->phone_normalized),
                ],
            );
        }

        $this->acquirePhoneCaptureLock($phoneNumber->phone_normalized);

        $duplicateRoots = $this->findDuplicateContactRootsByPhoneAction->handle(
            $phoneNumber->phone_normalized,
            $contact,
        );

        if ($duplicateRoots->hasMultipleOtherRoots) {
            $this->createPhoneDuplicateReview(
                contact: $contact,
                phoneNormalized: $phoneNumber->phone_normalized,
                matchedRootContactIds: $duplicateRoots->matchedRootContactIds,
                triggerMessage: $storedMessage,
                reason: 'Phone matched multiple other root contacts during phone capture.',
            );

            $this->channelActivityLogger->info(
                $channel,
                'contact.phone_review_pending_multiple_roots',
                'Найдено несколько root-контактов с тем же номером телефона. Автосклейка не выполнена.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $storedMessage->id,
                    'phone_normalized' => $phoneNumber->phone_normalized,
                    'matched_root_contact_ids' => $duplicateRoots->matchedRootContactIds,
                    'matched_root_count' => $duplicateRoots->matchedRootCount,
                ],
            );

            return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING;
        }

        if ($duplicateRoots->hasSingleOtherRoot) {
            try {
                $mergeResult = $this->mergeContactsAction->handle(
                    left: $contact,
                    right: $duplicateRoots->matchedRootContactIds[0],
                    mergeReason: 'phone_exact_match',
                    triggerPhone: $phoneNumber->phone_normalized,
                    triggerMessage: $storedMessage,
                );

                $storedMessage->refresh();

                $this->channelActivityLogger->info(
                    $channel,
                    'contact.phone_merged_to_existing_root',
                    'Контакты автоматически объединены по точному совпадению телефона.',
                    [
                        'contact_id' => $storedMessage->contact_id,
                        'channel_id' => $channel->id,
                        'message_id' => $storedMessage->id,
                        'phone_normalized' => $phoneNumber->phone_normalized,
                        'matched_root_contact_ids' => $duplicateRoots->matchedRootContactIds,
                        'matched_root_count' => $duplicateRoots->matchedRootCount,
                        'primary_contact_id' => $mergeResult->primaryContactId,
                        'secondary_contact_id' => $mergeResult->secondaryContactId,
                        'merge_log_id' => $mergeResult->mergeLogId,
                    ],
                );

                return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT;
            } catch (ContactMergeException|BrokenContactMergeChainException|DialogConsolidationException|QueryException $exception) {
                $this->createPhoneDuplicateReview(
                    contact: $contact,
                    phoneNormalized: $phoneNumber->phone_normalized,
                    matchedRootContactIds: $duplicateRoots->matchedRootContactIds,
                    triggerMessage: $storedMessage,
                    reason: 'Phone matched one other root contact but merge failed during phone capture.',
                );

                $this->channelActivityLogger->warning(
                    $channel,
                    'contact.phone_merge_failed_review_pending',
                    'Автосклейка по номеру не выполнена. Контакт отправлен на ручную проверку.',
                    [
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'message_id' => $storedMessage->id,
                        'phone_normalized' => $phoneNumber->phone_normalized,
                        'matched_root_contact_ids' => $duplicateRoots->matchedRootContactIds,
                        'matched_root_count' => $duplicateRoots->matchedRootCount,
                        'error' => $exception->getMessage(),
                    ],
                );

                return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_REVIEW_PENDING;
            }
        }

        if (! $phoneNumber->wasRecentlyCreated) {
            $this->channelActivityLogger->info(
                $channel,
                'contact.phone_duplicate_same_root_detected',
                'Номер уже существует у текущего root-контакта.',
                [
                    'contact_id' => $contact->id,
                    'channel_id' => $channel->id,
                    'message_id' => $storedMessage->id,
                    'phone_normalized' => $phoneNumber->phone_normalized,
                    'matched_root_contact_ids' => [],
                    'matched_root_count' => 0,
                ],
            );

            return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_DUPLICATE_SAME_ROOT;
        }

        return StoredInboundMessageResult::PHONE_CAPTURE_STATUS_CAPTURED_NEW;
    }

    private function acquirePhoneCaptureLock(string $phoneNormalized): void
    {
        DB::selectOne('SELECT pg_advisory_xact_lock(hashtext(?))', [$phoneNormalized]);
    }

    private function acquireCrossChannelIdentityLock(string $platform, string $externalUserId): void
    {
        DB::selectOne(
            'SELECT pg_advisory_xact_lock(hashtext(?))',
            ['cross_channel_identity:'.$platform.':'.$externalUserId],
        );
    }

    /**
     * @param  list<int>  $matchedRootContactIds
     */
    private function createPhoneDuplicateReview(
        Contact $contact,
        string $phoneNormalized,
        array $matchedRootContactIds,
        Message $triggerMessage,
        string $reason,
    ): void {
        $this->createContactDuplicateReviewAction->handle(
            contact: $contact,
            phoneNormalized: $phoneNormalized,
            reviewType: ContactDuplicateReview::TYPE_PHONE_OTHER_ROOT_CANDIDATE,
            candidateRootContactIds: $matchedRootContactIds,
            triggerMessage: $triggerMessage,
            reason: $reason,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    protected function extractPayloadKeysPreview(array $payload): array
    {
        $topLevelKeys = array_keys($payload);
        $messageKeys = is_array(data_get($payload, 'message'))
            ? array_map(fn (string|int $key): string => 'message.'.$key, array_keys((array) data_get($payload, 'message')))
            : [];
        $bodyKeys = is_array(data_get($payload, 'message.body'))
            ? array_map(fn (string|int $key): string => 'message.body.'.$key, array_keys((array) data_get($payload, 'message.body')))
            : [];

        return array_values(array_slice(array_map('strval', array_unique([
            ...$topLevelKeys,
            ...$messageKeys,
            ...$bodyKeys,
        ])), 0, 12));
    }
}
