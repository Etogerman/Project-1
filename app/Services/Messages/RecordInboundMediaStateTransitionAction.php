<?php

namespace App\Services\Messages;

use App\Models\MediaDownloadStateTransition;
use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class RecordInboundMediaStateTransitionAction
{
    public function handle(
        MessageAttachment $attachment,
        ?string $oldStatus,
        ?int $previousGeneration,
    ): void {
        if (! Schema::hasTable('media_download_state_transitions')) {
            return;
        }

        $generation = max(1, (int) $attachment->media_download_generation);
        $newStatus = MessageAttachment::normalizeDownloadStatus($attachment->download_status);
        $previousTransitionId = MediaDownloadStateTransition::query()
            ->where('message_attachment_id', $attachment->id)
            ->latest('id')
            ->value('id');
        [$actorType, $actorId] = $this->actor($attachment);

        MediaDownloadStateTransition::query()->create([
            'message_attachment_id' => $attachment->id,
            'channel_id' => $attachment->channel_id,
            'previous_transition_id' => $previousTransitionId,
            'previous_generation' => $previousGeneration,
            'generation' => $generation,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $this->action($oldStatus, $newStatus, $previousGeneration, $generation),
            'old_status' => MessageAttachment::normalizeDownloadStatus($oldStatus),
            'new_status' => $newStatus,
            'safe_reason' => filled($attachment->safe_error_code)
                ? mb_substr((string) $attachment->safe_error_code, 0, 128)
                : null,
            'expected_bytes' => $this->nonNegativeInteger($attachment->file_size_bytes),
            'actual_bytes' => $this->actualBytes($attachment, $newStatus),
            'transport' => mb_substr((string) $attachment->provider, 0, 32),
            'correlation_id' => hash('sha256', implode(':', [
                (string) $attachment->provider,
                (string) $attachment->id,
                (string) $generation,
            ])),
        ]);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function actor(MessageAttachment $attachment): array
    {
        if (
            $attachment->wasChanged('manual_download_requested_at')
            && $attachment->manual_download_requested_at !== null
        ) {
            $actorId = $this->nonNegativeInteger($attachment->manual_download_requested_by_user_id)
                ?? $this->nonNegativeInteger(Auth::id());

            return ['operator', $actorId];
        }

        return ['system', null];
    }

    private function action(
        ?string $oldStatus,
        ?string $newStatus,
        ?int $previousGeneration,
        int $generation,
    ): string {
        if ($oldStatus === null) {
            return 'initialized';
        }

        if ($previousGeneration !== null && $previousGeneration !== $generation) {
            return 'generation_started';
        }

        return match ($newStatus) {
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD => 'download_queued',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING => 'download_claimed',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED => 'download_succeeded',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED => 'download_failed',
            MessageAttachment::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND => 'available_on_demand',
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY => 'metadata_only',
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL => 'local_file_deleted',
            default => 'state_changed',
        };
    }

    private function actualBytes(MessageAttachment $attachment, ?string $newStatus): ?int
    {
        $uploadedBytes = $this->nonNegativeInteger($attachment->media_download_upload_size_bytes);

        if ($uploadedBytes !== null) {
            return $uploadedBytes;
        }

        return $newStatus === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
            ? $this->nonNegativeInteger($attachment->file_size_bytes)
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized >= 0 ? $normalized : null;
    }
}
