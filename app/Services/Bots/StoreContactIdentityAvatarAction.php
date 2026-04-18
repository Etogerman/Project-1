<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Models\ContactIdentity;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class StoreContactIdentityAvatarAction
{
    public function handle(ContactIdentity $identity, DownloadedAvatarData $avatar): void
    {
        if ($avatar->contents === '') {
            return;
        }

        $disk = $this->avatarStorage()->disk();
        $extension = $this->resolveExtension($avatar);
        $hash = sha1($avatar->contents);
        $path = sprintf('contact-identities/%d/avatar/%s.%s', $identity->id, $hash, $extension);
        $previousPath = $identity->avatar_path;

        if (! $disk->exists($path) && ! $disk->put($path, $avatar->contents)) {
            throw new RuntimeException('Avatar storage write failed.');
        }

        $identity->forceFill([
            'avatar_path' => $path,
            'avatar_updated_at' => now(),
        ])->save();

        if (filled($previousPath) && $previousPath !== $path) {
            $this->deleteFromKnownDisks($previousPath);
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
            $this->deleteFromKnownDisks($previousPath);
        }
    }

    protected function avatarStorage(): ContactIdentityAvatarStorage
    {
        return app(ContactIdentityAvatarStorage::class);
    }

    protected function deleteFromKnownDisks(string $path): void
    {
        $this->avatarStorage()->disk()->delete($path);
        Storage::disk('public')->delete($path);
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
        $contentType = $this->normalizeContentType($this->detectMimeTypeWithFileInfo($contents));

        if ($this->isSupportedContentType($contentType)) {
            return $contentType;
        }

        $contentType = $this->normalizeContentType($this->detectMimeTypeWithImageSize($contents));

        if ($this->isSupportedContentType($contentType)) {
            return $contentType;
        }

        throw new InvalidArgumentException('Unsupported avatar content type.');
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

    protected function isSupportedContentType(string $contentType): bool
    {
        return in_array($contentType, [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
            'image/gif',
        ], true);
    }
}
