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
                $contact = Contact::query()->create();

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

            if (
                filled($message->externalUsername)
                && $identity->external_username !== $message->externalUsername
            ) {
                $identity->forceFill([
                    'external_username' => $message->externalUsername,
                ])->save();
            }

            return Message::query()->create([
                'contact_id' => $identity->contact_id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'direction' => Message::DIRECTION_INBOUND,
                'external_chat_id' => $message->externalChatId,
                'external_message_id' => $message->externalMessageId,
                'text' => $message->text,
                'raw_payload' => $message->rawPayload,
                'received_at' => $message->receivedAt,
            ]);
        });
    }

    protected function wasUniqueConstraintViolation(QueryException $exception): bool
    {
        return ($exception->errorInfo[0] ?? null) === '23505';
    }
}
