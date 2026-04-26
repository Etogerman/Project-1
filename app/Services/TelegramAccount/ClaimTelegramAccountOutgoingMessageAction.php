<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\TelegramAccountOutgoingMessage;
use Illuminate\Support\Facades\DB;

class ClaimTelegramAccountOutgoingMessageAction
{
    public function handle(Channel $channel): ?TelegramAccountOutgoingMessage
    {
        return DB::transaction(function () use ($channel): ?TelegramAccountOutgoingMessage {
            $outgoing = TelegramAccountOutgoingMessage::query()
                ->where('channel_id', $channel->id)
                ->where('status', TelegramAccountOutgoingMessage::STATUS_PENDING)
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $outgoing instanceof TelegramAccountOutgoingMessage) {
                return null;
            }

            $outgoing->forceFill([
                'status' => TelegramAccountOutgoingMessage::STATUS_PROCESSING,
                'attempts' => $outgoing->attempts + 1,
                'claimed_at' => now(),
                'failed_at' => null,
                'last_error_message' => null,
            ])->save();

            return $outgoing->fresh(['message']);
        });
    }
}
