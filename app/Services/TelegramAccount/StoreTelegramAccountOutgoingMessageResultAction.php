<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\Message;
use App\Models\TelegramAccountOutgoingMessage;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StoreTelegramAccountOutgoingMessageResultAction
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(
        Channel $channel,
        TelegramAccountOutgoingMessage $outgoing,
        array $payload,
    ): TelegramAccountOutgoingMessage {
        if ((int) $outgoing->channel_id !== (int) $channel->id) {
            throw new InvalidArgumentException('Outgoing message does not belong to route channel.');
        }

        return DB::transaction(function () use ($channel, $outgoing, $payload): TelegramAccountOutgoingMessage {
            /** @var TelegramAccountOutgoingMessage $locked */
            $locked = TelegramAccountOutgoingMessage::query()
                ->whereKey($outgoing->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === TelegramAccountOutgoingMessage::STATUS_SENT) {
                return $locked;
            }

            $status = (string) ($payload['status'] ?? '');

            if ($status === TelegramAccountOutgoingMessage::STATUS_SENT) {
                return $this->markSent($channel, $locked, $payload);
            }

            if ($status === TelegramAccountOutgoingMessage::STATUS_FAILED) {
                return $this->markFailed($channel, $locked, $payload);
            }

            throw new InvalidArgumentException('Unsupported outgoing message result status.');
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markSent(
        Channel $channel,
        TelegramAccountOutgoingMessage $outgoing,
        array $payload,
    ): TelegramAccountOutgoingMessage {
        $externalMessageId = trim((string) ($payload['external_message_id'] ?? ''));

        if ($externalMessageId === '') {
            throw new InvalidArgumentException('external_message_id is required for sent outgoing message result.');
        }

        $resultPayload = is_array($payload['raw_payload'] ?? null) ? $payload['raw_payload'] : [];

        $outgoing->forceFill([
            'status' => TelegramAccountOutgoingMessage::STATUS_SENT,
            'sent_at' => now(),
            'failed_at' => null,
            'sent_external_message_id' => $externalMessageId,
            'last_error_message' => null,
            'result_payload' => $resultPayload,
        ])->save();

        $this->updateMessagePayload($outgoing, [
            'delivery_status' => TelegramAccountOutgoingMessage::STATUS_SENT,
            'external_message_id' => $externalMessageId,
            'sent_at' => $outgoing->sent_at?->toIso8601String(),
            'result_payload' => $resultPayload,
        ], $externalMessageId);

        $channel->markReplySent();

        return $outgoing->fresh(['message']) ?? $outgoing;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markFailed(
        Channel $channel,
        TelegramAccountOutgoingMessage $outgoing,
        array $payload,
    ): TelegramAccountOutgoingMessage {
        $errorMessage = trim((string) ($payload['error_message'] ?? 'Gateway failed to send outgoing message.'));
        $resultPayload = is_array($payload['raw_payload'] ?? null) ? $payload['raw_payload'] : [];

        $outgoing->forceFill([
            'status' => TelegramAccountOutgoingMessage::STATUS_FAILED,
            'failed_at' => now(),
            'last_error_message' => $errorMessage,
            'result_payload' => $resultPayload,
        ])->save();

        $this->updateMessagePayload($outgoing, [
            'delivery_status' => TelegramAccountOutgoingMessage::STATUS_FAILED,
            'failed_at' => $outgoing->failed_at?->toIso8601String(),
            'error_message' => $errorMessage,
            'result_payload' => $resultPayload,
        ]);

        $channel->markError($errorMessage);

        return $outgoing->fresh(['message']) ?? $outgoing;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    private function updateMessagePayload(
        TelegramAccountOutgoingMessage $outgoing,
        array $updates,
        ?string $externalMessageId = null,
    ): void {
        $message = $outgoing->message;

        if (! $message instanceof Message) {
            return;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $message->forceFill([
            'external_message_id' => $externalMessageId ?? $message->external_message_id,
            'raw_payload' => array_merge($rawPayload, $updates),
        ])->save();
    }
}
