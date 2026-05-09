<?php

namespace App\Listeners;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;

class RecordAdminUserLogin
{
    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        if (Filament::getCurrentPanel()?->getId() !== 'admin') {
            return;
        }

        $event->user
            ->forceFill(['last_login_at' => now()])
            ->saveQuietly();
    }
}
