<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Data\Bots\StoredInboundMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactDuplicateReview;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\Contacts\AddContactPhoneAction;
use App\Services\Contacts\BrokenContactMergeChainException;
use App\Services\Contacts\ContactMergeException;
use App\Services\Contacts\CreateContactDuplicateReviewAction;
use App\Services\Contacts\FindDuplicateContactRootsByPhoneAction;
use App\Services\Contacts\MergeContactsAction;
use App\Services\Dialogs\SyncDialogConfirmedPhoneAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageAction
{
    public function __construct(
        protected AddContactPhoneAction $addContactPhoneAction,
        protected FindDuplicateContactRootsByPhoneAction $findDuplicateContactRootsByPhoneAction,
        protected CreateContactDuplicateReviewAction $createContactDuplicateReviewAction,
        protected MergeContactsAction $mergeContactsAction,
        protected ChannelActivityLogger $channelActivityLogger,
        protected SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        protected SyncDialogConfirmedPhoneAction $syncDialogConfirmedPhoneAction,
    ) {}

    public function handle(Channel $channel, IncomingBotMessage $message): StoredInboundMessageResult
    {
        return DB::transaction(function () use ($channel, $message): StoredInboundMessageResult {
            $identity = ContactIdentity::query()
                ->with('contact')
                ->where('channel_id', $channel->id)
                ->where('external_user_id', $message->externalUserId)
                ->first();

            if ($identity === null) {
                $contact = Contact::query()->create([
                    'name' => $message->contactName,
                ]);

                try {
                    $identity = ContactIdentity::query()->create([
                        'contact_id' => $contact->id,
                        'channel_id' => $channel->id,
                        'platform' => $message->platform,
                        'external_user_id' => $message->externalUserId,
                        'external_username' => $message->externalUsername,
                    ])->load('contact');
                } catch (QueryException $exception) {
                    if (! $this->wasUniqueConstraintViolation($exception)) {
                        throw $exception;
                    }

                    $contact->delete();

                    $identity = ContactIdentity::query()
                        ->with('contact')
                        ->where('channel_id', $channel->id)
                        ->where('external_user_id', $message->externalUserId)
                        ->firstOrFail();
                }
            }

            $contact = $identity->contact;

            if (
                $contact !== null
                && filled($message->contactName)
                && $contact->name !== $message->contactName
            ) {
                $contact->forceFill([
                    'name' => $message->contactName,
                ])->save();
            }

            if (
                filled($message->externalUsername)
                && $identity->external_username !== $message->externalUsername
            ) {
                $identity->forceFill([
                    'external_username' => $message->externalUsername,
                ])->save();
            }

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

                    return new StoredInboundMessageResult(
                        message: $existingMessage,
                        phoneCaptureStatus: $phoneCaptureStatus,
                    );
                }
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
                    'raw_payload' => $message->rawPayload,
                    'received_at' => $message->receivedAt,
                ]);

                $phoneCaptureStatus = $this->captureSharedPhoneIfNeeded($channel, $contact, $storedMessage, $message);

                if ($phoneCaptureStatus === StoredInboundMessageResult::PHONE_CAPTURE_STATUS_MERGED_TO_ROOT) {
                    $storedMessage->refresh()->load(['contact', 'contactIdentity']);
                }

                $this->syncStoredInboundMessageMetadata($channel, $contact, $storedMessage, $message);
                $this->syncDialogConfirmedPhoneIfNeeded($storedMessage, $message, $phoneCaptureStatus);

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

                return new StoredInboundMessageResult(
                    message: $existingMessage,
                    phoneCaptureStatus: $phoneCaptureStatus,
                );
            }
        });
    }

    protected function findExistingInboundMessage(Channel $channel, string $providerEventKey): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('provider_event_key', $providerEventKey)
            ->first();
    }

    protected function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }

    protected function resolveInboundMessageKind(IncomingBotMessage $message): string
    {
        return $message->inboundKind === IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
            ? Message::KIND_INBOUND_CONTACT_SHARE
            : Message::KIND_INBOUND_USER;
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
            Message::SENT_BY_TYPE_CONTACT,
        );
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
            } catch (ContactMergeException|BrokenContactMergeChainException|QueryException $exception) {
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
