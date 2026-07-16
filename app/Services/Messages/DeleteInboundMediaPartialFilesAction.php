<?php

namespace App\Services\Messages;

use App\Models\MessageAttachment;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class DeleteInboundMediaPartialFilesAction
{
    public function scopedBytes(
        MessageAttachment $attachment,
        ?string $claimToken = null,
        ?int $generation = null,
    ): ?int {
        try {
            $disk = Storage::disk(MessageAttachment::storageDiskName());
            $bytes = 0;

            foreach ($this->matchingPaths($attachment, $claimToken, $generation) as $path) {
                $bytes += max(0, (int) $disk->size($path));
            }

            return $bytes;
        } catch (Throwable $exception) {
            $this->logFailure($attachment, $exception::class);

            return null;
        }
    }

    public function handle(
        MessageAttachment $attachment,
        ?string $claimToken = null,
        ?int $generation = null,
    ): bool {
        try {
            $disk = Storage::disk(MessageAttachment::storageDiskName());
            $paths = $this->matchingPaths($attachment, $claimToken, $generation);

            if ($paths !== [] && ! $disk->delete($paths)) {
                $this->logFailure($attachment, 'delete_returned_false');

                return false;
            }

            foreach ($paths as $path) {
                if ($disk->exists($path)) {
                    $this->logFailure($attachment, 'file_still_exists');

                    return false;
                }
            }

            return true;
        } catch (Throwable $exception) {
            $this->logFailure($attachment, $exception::class);

            return false;
        }
    }

    private function logFailure(MessageAttachment $attachment, string $errorType): void
    {
        Log::warning('inbound_media.partial_cleanup_failed', [
            'attachment_id' => $attachment->getKey(),
            'error_type' => $errorType,
        ]);
    }

    private function safeClaimToken(string $claimToken): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($claimToken)) ?? '';
    }

    /**
     * @return list<string>
     */
    private function matchingPaths(
        MessageAttachment $attachment,
        ?string $claimToken,
        ?int $generation,
    ): array {
        $directory = MessageAttachment::LOCAL_PATH_PREFIX.'/'.$attachment->message_id;
        $prefix = $attachment->getKey().'.';
        $safeClaimToken = $claimToken !== null
            ? $this->safeClaimToken($claimToken)
            : null;
        $generation = $generation !== null ? max(1, $generation) : null;

        return array_values(array_filter(
            Storage::disk(MessageAttachment::storageDiskName())->files($directory),
            static function (string $path) use ($prefix, $safeClaimToken, $generation): bool {
                $basename = basename($path);

                if (! str_starts_with($basename, $prefix)) {
                    return false;
                }

                if ($generation === null) {
                    return str_contains($basename, '.partial.') || str_ends_with($basename, '.upload');
                }

                $generationMarker = '.g'.$generation.'.';

                if (! str_contains($basename, $generationMarker)) {
                    return false;
                }

                if ($safeClaimToken === null) {
                    return str_contains($basename, '.partial'.$generationMarker)
                        || str_ends_with($basename, '.upload');
                }

                return str_contains($basename, '.partial'.$generationMarker.$safeClaimToken.'.')
                    || str_ends_with($basename, $generationMarker.$safeClaimToken.'.upload');
            },
        ));
    }
}
