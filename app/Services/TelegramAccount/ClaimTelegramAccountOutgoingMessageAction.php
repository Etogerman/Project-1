<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use Illuminate\Support\Facades\DB;

class ClaimTelegramAccountOutgoingMessageAction
{
    private const PROCESSING_TIMEOUT_MINUTES = 10;

    public function handle(Channel $channel): ?TelegramAccountOutgoingMessage
    {
        return DB::transaction(function () use ($channel): ?TelegramAccountOutgoingMessage {
            $this->failStaleProcessingMessages($channel);

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

    private function failStaleProcessingMessages(Channel $channel): void
    {
        $errorMessage = 'Gateway did not report delivery result before processing timeout.';
        $failedAt = now();

        $staleMessages = TelegramAccountOutgoingMessage::query()
            ->with('message')
            ->where('channel_id', $channel->id)
            ->where('status', TelegramAccountOutgoingMessage::STATUS_PROCESSING)
            ->whereNotNull('claimed_at')
            ->where('claimed_at', '<=', $failedAt->copy()->subMinutes(self::PROCESSING_TIMEOUT_MINUTES))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($staleMessages as $outgoing) {
            $outgoing->forceFill([
                'status' => TelegramAccountOutgoingMessage::STATUS_FAILED,
                'failed_at' => $failedAt,
                'last_error_message' => $errorMessage,
            ])->save();

            $this->updateMessagePayload($outgoing, [
                'delivery_status' => TelegramAccountOutgoingMessage::STATUS_FAILED,
                'failed_at' => $failedAt->toIso8601String(),
                'error_message' => $errorMessage,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function updateMessagePayload(TelegramAccountOutgoingMessage $outgoing, array $updates): void
    {
        $message = $outgoing->message;

        if (! $message instanceof Message) {
            return;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $message->forceFill([
            'raw_payload' => array_merge($rawPayload, $updates),
        ])->save();
    }
}
