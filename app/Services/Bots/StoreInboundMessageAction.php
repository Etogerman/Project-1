<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\Contacts\AddContactPhoneAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageAction
{
    public function __construct(
        protected AddContactPhoneAction $addContactPhoneAction,
        protected ChannelActivityLogger $channelActivityLogger,
    ) {}

    public function handle(Channel $channel, IncomingBotMessage $message): Message
    {
        return DB::transaction(function () use ($channel, $message): Message {
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
                    return $existingMessage;
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

                $this->captureSharedPhoneIfNeeded($channel, $contact, $storedMessage, $message);

                return $storedMessage;
            } catch (QueryException $exception) {
                if (! filled($message->providerEventKey) || ! $this->wasUniqueConstraintViolation($exception)) {
                    throw $exception;
                }

                return $this->findExistingInboundMessage($channel, $message->providerEventKey) ?? throw $exception;
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

    protected function captureSharedPhoneIfNeeded(
        Channel $channel,
        ?Contact $contact,
        Message $storedMessage,
        IncomingBotMessage $message,
    ): void {
        if (
            $contact === null
            || $message->inboundKind !== IncomingBotMessage::KIND_INBOUND_CONTACT_SHARE
            || ! filled($message->sharedPhoneNumber)
        ) {
            return;
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

            return;
        }

        $phoneNumber = $this->addContactPhoneAction->handle(
            $contact,
            $message->sharedPhoneNumber,
            ContactPhoneNumber::SOURCE_TELEGRAM_CONTACT_SHARE,
        );

        if (! $phoneNumber->wasRecentlyCreated) {
            return;
        }

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
}
