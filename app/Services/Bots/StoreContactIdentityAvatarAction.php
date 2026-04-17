<?php

namespace App\Services\Bots;

use App\Data\Bots\DownloadedAvatarData;
use App\Models\ContactIdentity;
use Illuminate\Support\Facades\Storage;

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
        $filenameHint = $avatar->filenameHint;

        if (filled($filenameHint)) {
            $path = parse_url($filenameHint, PHP_URL_PATH);
            $extension = $path !== false ? pathinfo((string) $path, PATHINFO_EXTENSION) : null;

            if (is_string($extension) && $extension !== '') {
                return strtolower($extension);
            }
        }

        return match ($avatar->contentType) {
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'jpg',
        };
    }
}
