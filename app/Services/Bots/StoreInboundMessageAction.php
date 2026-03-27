<?php

namespace App\Services\Bots;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Message;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class StoreInboundMessageAction
{
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
                return Message::query()->create([
                    'contact_id' => $identity->contact_id,
                    'contact_identity_id' => $identity->id,
                    'channel_id' => $channel->id,
                    'direction' => Message::DIRECTION_INBOUND,
                    'provider_event_key' => $message->providerEventKey,
                    'external_chat_id' => $message->externalChatId,
                    'external_message_id' => $message->externalMessageId,
                    'text' => $message->text,
                    'raw_payload' => $message->rawPayload,
                    'received_at' => $message->receivedAt,
                ]);
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
}
