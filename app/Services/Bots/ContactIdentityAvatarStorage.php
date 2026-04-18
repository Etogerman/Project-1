<?php

namespace App\Services\Bots;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

class ContactIdentityAvatarStorage
{
    public function diskName(): string
    {
        return (string) config('filesystems.contact_avatars_disk', 'contact_avatars');
    }

    public function disk(): FilesystemAdapter
    {
        return Storage::disk($this->diskName());
    }

    public function exists(?string $path): bool
    {
        return filled($path) && $this->disk()->exists((string) $path);
    }

    public function url(string $path): string
    {
        return $this->disk()->url($path);
    }
}
