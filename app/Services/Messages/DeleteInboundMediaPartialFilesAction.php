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
            $transferredBytes = 0;
            $commitFallbackBytes = 0;

            foreach ($this->matchingPaths($attachment, $claimToken, $generation) as $path) {
                $bytes = max(0, (int) $disk->size($path));

                if (str_contains(basename($path), '.commit.')) {
                    $commitFallbackBytes = max($commitFallbackBytes, $bytes);

                    continue;
                }

                $transferredBytes += $bytes;
            }

            return $transferredBytes > 0
                ? $transferredBytes
                : $commitFallbackBytes;
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
        $referencedPath = $attachment->local_disk === MessageAttachment::storageDiskName()
            && filled($attachment->local_path)
                ? (string) $attachment->local_path
                : null;
        $currentClaimToken = trim((string) $attachment->media_download_claim_token);
        $activeClaimToken = $attachment->download_status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
            && $currentClaimToken !== ''
            && ! str_starts_with($currentClaimToken, 'revoked-')
                ? $this->safeClaimToken($currentClaimToken)
                : null;
        $activeGeneration = max(1, (int) $attachment->media_download_generation);

        return array_values(array_filter(
            Storage::disk(MessageAttachment::storageDiskName())->files($directory),
            static function (string $path) use (
                $prefix,
                $safeClaimToken,
                $generation,
                $referencedPath,
                $activeClaimToken,
                $activeGeneration,
            ): bool {
                if ($referencedPath !== null && $path === $referencedPath) {
                    return false;
                }

                $basename = basename($path);

                if (! str_starts_with($basename, $prefix)) {
                    return false;
                }

                if ($safeClaimToken === null && $activeClaimToken !== null) {
                    $activeGenerationMarker = '.g'.$activeGeneration.'.'.$activeClaimToken;

                    if (
                        str_contains(
                            $basename,
                            '.partial.g'.$activeGeneration.'.'.$activeClaimToken.'.',
                        )
                        || str_ends_with($basename, $activeGenerationMarker.'.upload')
                        || str_contains($basename, $activeGenerationMarker.'.commit.')
                    ) {
                        return false;
                    }
                }

                if ($generation === null) {
                    return str_contains($basename, '.partial.')
                        || str_ends_with($basename, '.upload')
                        || str_contains($basename, '.commit.');
                }

                $generationMarker = '.g'.$generation.'.';

                if (! str_contains($basename, $generationMarker)) {
                    return false;
                }

                if ($safeClaimToken === null) {
                    return str_contains($basename, '.partial'.$generationMarker)
                        || str_ends_with($basename, '.upload')
                        || str_contains($basename, '.commit.');
                }

                return str_contains($basename, '.partial'.$generationMarker.$safeClaimToken.'.')
                    || str_ends_with($basename, $generationMarker.$safeClaimToken.'.upload')
                    || str_contains($basename, $generationMarker.$safeClaimToken.'.commit.');
            },
        ));
    }
}
