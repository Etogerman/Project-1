<?php

namespace App\Services\Messages;

use App\Jobs\DownloadBotMessageAttachmentJob;
use App\Models\Dialog;
use App\Models\MediaDownloadStorageLedger;
use App\Models\MediaDownloadTrafficLedger;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestInboundMediaDownloadAction
{
    public function __construct(
        private readonly InboundMediaDownloadPolicy $mediaDownloadPolicy,
    ) {}

    public function handle(Dialog $dialog, MessageAttachment $attachment, User $operator): MessageAttachment
    {
        $requested = DB::transaction(function () use ($dialog, $attachment, $operator): MessageAttachment {
            $messageId = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->value('message_id');

            if ($messageId === null) {
                throw new InvalidArgumentException('Файл не относится к сообщению диалога.');
            }

            /** @var Dialog $lockedDialog */
            $lockedDialog = Dialog::query()
                ->whereKey($dialog->id)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Message $lockedMessage */
            $lockedMessage = Message::query()
                ->whereKey($messageId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var MessageAttachment $lockedAttachment */
            $lockedAttachment = MessageAttachment::query()
                ->with('channel')
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (
                (int) $lockedAttachment->channel_id !== (int) $lockedDialog->channel_id
                || (int) $lockedAttachment->message_id !== (int) $lockedMessage->id
                || (int) $lockedMessage->dialog_id !== (int) $lockedDialog->id
            ) {
                throw new InvalidArgumentException('Файл не относится к этому диалогу.');
            }

            if (! $operator->canDownloadMediaManually()) {
                throw new InvalidArgumentException('Недостаточно прав для ручной загрузки файла.');
            }

            if ($lockedAttachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $lockedAttachment;
            }

            if (in_array($lockedAttachment->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            ], true)) {
                return $lockedAttachment;
            }

            $availability = $this->mediaDownloadPolicy->manualAvailability($lockedAttachment);

            if (! $availability['allowed']) {
                throw new InvalidArgumentException(
                    $availability['reason'] ?? 'Файл больше недоступен для ручной загрузки.',
                );
            }

            $generation = max(1, (int) $lockedAttachment->media_download_generation);
            $startsNewGeneration = in_array($lockedAttachment->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
                MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
            ], true);

            if ($startsNewGeneration) {
                $this->assertPreviousGenerationFinalized($lockedAttachment, $generation);
                $generation++;
            }

            $lockedAttachment->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                'manual_download_requested_at' => now(),
                'manual_download_requested_by_user_id' => $operator->id,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'media_download_max_bytes' => $this->mediaDownloadPolicy->manualRequestMaxBytes($lockedAttachment),
                'media_download_generation' => $generation,
                'media_download_attempts' => $startsNewGeneration
                    ? 0
                    : (int) $lockedAttachment->media_download_attempts,
                'media_download_lease_sequence' => $startsNewGeneration
                    ? 0
                    : (int) $lockedAttachment->media_download_lease_sequence,
                'media_download_trigger' => null,
                'media_download_claimed_at' => null,
                'media_download_heartbeat_at' => null,
                'media_download_attempt_deadline_at' => null,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            return $lockedAttachment->fresh(['channel']);
        });

        if (in_array($requested->provider, [
            MessageAttachment::PROVIDER_TELEGRAM_BOT,
            MessageAttachment::PROVIDER_MAX_BOT,
        ], true)) {
            DownloadBotMessageAttachmentJob::dispatch((int) $requested->id)->afterCommit();
        }

        return $requested;
    }

    private function assertPreviousGenerationFinalized(
        MessageAttachment $attachment,
        int $generation,
    ): void {
        $hasActiveStorage = MediaDownloadStorageLedger::query()
            ->where('message_attachment_id', $attachment->getKey())
            ->where('generation', $generation)
            ->whereIn('status', [
                MediaDownloadStorageLedger::STATUS_RESERVED,
                MediaDownloadStorageLedger::STATUS_USED,
            ])
            ->exists();
        $hasActiveTraffic = MediaDownloadTrafficLedger::query()
            ->where('message_attachment_id', $attachment->getKey())
            ->where('generation', $generation)
            ->where('status', MediaDownloadTrafficLedger::STATUS_RESERVED)
            ->exists();

        if ($hasActiveStorage || $hasActiveTraffic) {
            throw new InvalidArgumentException('Предыдущая загрузка файла ещё не завершена.');
        }
    }
}
