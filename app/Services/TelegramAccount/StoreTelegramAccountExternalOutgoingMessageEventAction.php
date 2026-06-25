<?php

namespace App\Services\TelegramAccount;

use App\Data\TelegramAccount\NormalizedExternalOutgoingMessageEvent;
use App\Data\TelegramAccount\StoreExternalOutgoingMessageResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Dialogs\ResolveOrCreateDialogAction;
use App\Services\Dialogs\SyncMessageDialogMetadataAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StoreTelegramAccountExternalOutgoingMessageEventAction
{
    public function __construct(
        private readonly ResolveOrCreateDialogAction $resolveOrCreateDialogAction,
        private readonly SyncMessageDialogMetadataAction $syncMessageDialogMetadataAction,
        private readonly ChannelActivityLogger $channelActivityLogger,
    ) {}

    public function handle(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
    ): StoreExternalOutgoingMessageResult {
        return DB::transaction(function () use ($channel, $event): StoreExternalOutgoingMessageResult {
            $skipReason = $this->resolveSkipReason($channel, $event);

            if ($skipReason !== null) {
                $this->logSkipped($channel, $event, $skipReason);

                return StoreExternalOutgoingMessageResult::skipped($skipReason);
            }

            $abOriginOutgoing = $this->findAbOriginOutgoing($channel, $event);

            if ($abOriginOutgoing instanceof TelegramAccountOutgoingMessage) {
                $this->logSkipped(
                    $channel,
                    $event,
                    NormalizedExternalOutgoingMessageEvent::SKIP_AB_ORIGIN_OUTGOING_MESSAGE,
                );

                return StoreExternalOutgoingMessageResult::skipped(
                    NormalizedExternalOutgoingMessageEvent::SKIP_AB_ORIGIN_OUTGOING_MESSAGE,
                    $abOriginOutgoing->message,
                );
            }

            $identity = $this->resolveIdentity($channel, $event);
            $dialog = $this->resolveDialog($channel, $event, $identity);

            if (! $identity instanceof ContactIdentity || ! $dialog instanceof Dialog) {
                $this->logSkipped($channel, $event, NormalizedExternalOutgoingMessageEvent::SKIP_UNKNOWN_BACKFILL_DIALOG);

                return StoreExternalOutgoingMessageResult::skipped(
                    NormalizedExternalOutgoingMessageEvent::SKIP_UNKNOWN_BACKFILL_DIALOG,
                );
            }

            $existingMessage = $this->findExistingExternalOutgoingMessage($channel, $event);

            if ($existingMessage instanceof Message) {
                $this->syncExternalOutgoingMessageMetadata($channel, $event, $identity, $dialog, $existingMessage);

                return StoreExternalOutgoingMessageResult::stored($existingMessage);
            }

            try {
                $message = Message::query()->create([
                    'contact_id' => $identity->contact_id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_OUTBOUND,
                    'message_kind' => Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE,
                    'reply_to_message_id' => null,
                    'provider_event_key' => $event->messageKey,
                    'external_chat_id' => $event->externalChatId,
                    'external_message_id' => $event->externalMessageId,
                    'text' => $event->text,
                    'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                    'source_text' => null,
                    'raw_payload' => $this->buildRawPayload($event),
                    'received_at' => $this->normalizeOccurredAtForStorage($event->occurredAt),
                ]);
            } catch (QueryException $exception) {
                if (! $this->wasUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                $message = $this->findExistingExternalOutgoingMessage($channel, $event) ?? throw $exception;
            }

            $message = $this->syncExternalOutgoingMessageMetadata($channel, $event, $identity, $dialog, $message);

            return StoreExternalOutgoingMessageResult::stored($message);
        });
    }

    private function resolveSkipReason(Channel $channel, NormalizedExternalOutgoingMessageEvent $event): ?string
    {
        if (! $channel->sync_external_outgoing_enabled) {
            return NormalizedExternalOutgoingMessageEvent::SKIP_SYNC_DISABLED;
        }

        if (! $event->isPrivatePeer()) {
            return NormalizedExternalOutgoingMessageEvent::SKIP_UNSUPPORTED_PEER_TYPE;
        }

        if ($event->isArchived) {
            return NormalizedExternalOutgoingMessageEvent::SKIP_ARCHIVED_CHAT;
        }

        if ($event->isBotUser) {
            return NormalizedExternalOutgoingMessageEvent::SKIP_BOT_USER;
        }

        if (! $event->isTextContent()) {
            return NormalizedExternalOutgoingMessageEvent::SKIP_UNSUPPORTED_CONTENT_TYPE;
        }

        return null;
    }

    private function findAbOriginOutgoing(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
    ): ?TelegramAccountOutgoingMessage {
        return TelegramAccountOutgoingMessage::query()
            ->with('message')
            ->where('channel_id', $channel->id)
            ->where('external_chat_id', $event->externalChatId)
            ->where('sent_external_message_id', $event->externalMessageId)
            ->first();
    }

    private function resolveIdentity(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
    ): ?ContactIdentity {
        $identity = ContactIdentity::query()
            ->with('contact')
            ->where('channel_id', $channel->id)
            ->where('external_user_id', $event->externalUserId)
            ->lockForUpdate()
            ->first();

        if ($identity instanceof ContactIdentity) {
            $this->syncIdentityProfile($identity, $event);

            return $identity;
        }

        $dialog = $this->findExistingDialogByExternalChat($channel, $event);

        if ($event->isBackfill()) {
            return $dialog?->currentContactIdentity;
        }

        $contact = $dialog?->contact ?? Contact::query()->create([
            'name' => $this->resolveContactName($event),
            'is_auto_reply_enabled' => true,
            'duplicate_review_status' => Contact::DUPLICATE_REVIEW_STATUS_NONE,
        ]);

        $identity = ContactIdentity::query()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $event->externalUserId,
            'external_username' => $event->externalUsername,
            'display_name' => $event->contactName,
        ]);

        return $identity->fresh(['contact']);
    }

    private function resolveDialog(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
        ?ContactIdentity $identity,
    ): ?Dialog {
        $dialog = $this->findExistingDialogByExternalChat($channel, $event);

        if ($dialog instanceof Dialog) {
            return $dialog;
        }

        if (! $identity instanceof ContactIdentity) {
            return null;
        }

        if ($event->isBackfill()) {
            return null;
        }

        return $this->resolveOrCreateDialogAction->handle($identity->contact_id, $channel);
    }

    private function findExistingDialogByExternalChat(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
    ): ?Dialog {
        return Dialog::query()
            ->with(['contact', 'currentContactIdentity'])
            ->where('channel_id', $channel->id)
            ->where('external_chat_id', $event->externalChatId)
            ->lockForUpdate()
            ->first();
    }

    private function findExistingExternalOutgoingMessage(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
    ): ?Message {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('direction', Message::DIRECTION_OUTBOUND)
            ->where(function ($query) use ($event): void {
                $query
                    ->where('provider_event_key', $event->messageKey)
                    ->orWhere(function ($query) use ($event): void {
                        $query
                            ->where('message_kind', Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE)
                            ->where('external_chat_id', $event->externalChatId)
                            ->where('external_message_id', $event->externalMessageId);
                    });
            })
            ->lockForUpdate()
            ->first();
    }

    private function syncExternalOutgoingMessageMetadata(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
        ContactIdentity $identity,
        Dialog $dialog,
        Message $message,
    ): Message {
        return $this->syncMessageDialogMetadataAction->handle(
            $message,
            $identity->contact_id,
            $channel,
            $identity,
            $event->externalChatId,
            Message::SENT_BY_TYPE_SYSTEM,
            null,
            Message::SENT_BY_SYSTEM_CODE_TELEGRAM_EXTERNAL_ACCOUNT,
        );
    }

    private function syncIdentityProfile(
        ContactIdentity $identity,
        NormalizedExternalOutgoingMessageEvent $event,
    ): void {
        $payload = [];

        if (filled($event->externalUsername) && $identity->external_username !== $event->externalUsername) {
            $payload['external_username'] = $event->externalUsername;
        }

        if (filled($event->contactName) && $identity->display_name !== $event->contactName) {
            $payload['display_name'] = $event->contactName;
        }

        if ($payload !== []) {
            $identity->forceFill($payload)->save();
        }
    }

    private function resolveContactName(NormalizedExternalOutgoingMessageEvent $event): string
    {
        if (filled($event->contactName)) {
            return (string) $event->contactName;
        }

        if (filled($event->externalUsername)) {
            return '@'.$event->externalUsername;
        }

        return 'Telegram user '.$event->externalUserId;
    }

    private function normalizeOccurredAtForStorage(Carbon $occurredAt): Carbon
    {
        return $occurredAt->copy()->setTimezone((string) config('app.timezone'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRawPayload(NormalizedExternalOutgoingMessageEvent $event): array
    {
        $rawPayload = $event->rawPayload;
        $rawPayload['_gateway_event'] = [
            'schema_version' => $event->schemaVersion,
            'gateway_event_id' => $event->gatewayEventId,
            'peer_key' => $event->peerKey,
            'message_key' => $event->messageKey,
            'peer_type' => $event->peerType,
            'history_source' => $event->historySource,
            'direction' => $event->direction,
            'source' => $event->source,
            'content_type' => $event->contentType,
            'is_archived' => $event->isArchived,
            'is_bot_user' => $event->isBotUser,
        ];

        return $rawPayload;
    }

    private function logSkipped(
        Channel $channel,
        NormalizedExternalOutgoingMessageEvent $event,
        string $skipReason,
    ): void {
        $this->channelActivityLogger->info(
            $channel,
            'telegram_account_gateway.external_outgoing_skipped',
            'External outgoing Telegram account event пропущен.',
            [
                'skip_reason' => $skipReason,
                'gateway_event_id' => $event->gatewayEventId,
                'peer_key' => $event->peerKey,
                'message_key' => $event->messageKey,
                'history_source' => $event->historySource,
            ],
        );
    }

    private function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
