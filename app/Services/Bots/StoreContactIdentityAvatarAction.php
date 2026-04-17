<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Models\ContactIdentity;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

class StoreContactIdentityAvatarAction
{
    public function handle(ContactIdentity $identity, DownloadedAvatarData $avatar): void
    {
        if ($avatar->contents === '') {
            return;
        }

        $disk = Storage::disk('public');
        $extension = $this->resolveExtension($avatar);
        $hash = sha1($avatar->contents);
        $path = sprintf('contact-identities/%d/avatar/%s.%s', $identity->id, $hash, $extension);
        $previousPath = $identity->avatar_path;

        if (! $disk->exists($path)) {
            $disk->put($path, $avatar->contents);
        }

        $identity->forceFill([
            'avatar_path' => $path,
            'avatar_updated_at' => now(),
        ])->save();

        if (filled($previousPath) && $previousPath !== $path) {
            $disk->delete($previousPath);
        }
    }

    public function clear(ContactIdentity $identity): void
    {
        $previousPath = $identity->avatar_path;

        $identity->forceFill([
            'avatar_path' => null,
            'avatar_updated_at' => null,
        ])->save();

        if (filled($previousPath)) {
            Storage::disk('public')->delete($previousPath);
        }
    }

    protected function resolveExtension(DownloadedAvatarData $avatar): string
    {
        $contentType = $this->detectContentTypeFromContents($avatar->contents);

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => throw new InvalidArgumentException('Unsupported avatar content type.'),
        };
    }

    protected function normalizeContentType(?string $contentType): string
    {
        return strtolower(trim((string) strtok((string) $contentType, ';')));
    }

    protected function detectContentTypeFromContents(string $contents): string
    {
        $contentType = $this->normalizeContentType(
            $this->detectMimeTypeWithFileInfo($contents)
            ?? $this->detectMimeTypeWithImageSize($contents),
        );

        if ($contentType === '') {
            throw new InvalidArgumentException('Unsupported avatar content type.');
        }

        return $contentType;
    }

    protected function detectMimeTypeWithFileInfo(string $contents): ?string
    {
        $fileInfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $fileInfo->buffer($contents);

        return is_string($mimeType) && $mimeType !== ''
            ? $mimeType
            : null;
    }

    protected function detectMimeTypeWithImageSize(string $contents): ?string
    {
        $imageInfo = @getimagesizefromstring($contents);

        return is_array($imageInfo) && is_string($imageInfo['mime'] ?? null) && $imageInfo['mime'] !== ''
            ? (string) $imageInfo['mime']
            : null;
    }
}
