<?php

namespace App\Services\TelegramAccount;

use App\Models\Dialog;
use App\Models\MessageAttachment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class RequestTelegramAccountMediaDownloadAction
{
    public function __construct(
        private readonly TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
    ) {}

    public function handle(Dialog $dialog, MessageAttachment $attachment, User $operator): MessageAttachment
    {
        return DB::transaction(function () use ($dialog, $attachment, $operator): MessageAttachment {
            /** @var MessageAttachment $locked */
            $locked = MessageAttachment::query()
                ->whereKey($attachment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $locked->loadMissing('message');

            if (
                $locked->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
                || (int) $locked->channel_id !== (int) $dialog->channel_id
                || (int) $locked->message?->dialog_id !== (int) $dialog->id
            ) {
                throw new InvalidArgumentException('Файл не относится к этому диалогу Telegram account.');
            }

            if (! $operator->canReplyInDialogs()) {
                throw new InvalidArgumentException('Недостаточно прав для ручной загрузки файла.');
            }

            if ($locked->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                return $locked;
            }

            if (in_array($locked->download_status, [
                MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            ], true)) {
                return $locked;
            }

            if (! $this->mediaDownloadPolicy->canRequestManually($locked)) {
                throw new InvalidArgumentException('Файл больше недоступен для ручной загрузки из Telegram.');
            }

            $locked->forceFill([
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
                'manual_download_requested_at' => now(),
                'manual_download_requested_by_user_id' => $operator->id,
                'media_download_claim_token' => null,
                'media_download_upload_size_bytes' => null,
                'media_download_next_retry_at' => null,
                'local_disk' => null,
                'local_path' => null,
                'safe_error_code' => null,
                'safe_error_message' => null,
            ])->save();

            return $locked->fresh();
        });
    }
}
