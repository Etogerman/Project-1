<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdminUserSeeder extends Seeder
{
    private const ADMIN_EMAIL = 'admin@abrikosoff.local';

    private const PASSWORD_ENV = 'ADMIN_USER_SEEDER_PASSWORD';

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        $user = User::query()->firstOrNew([
            'email' => self::ADMIN_EMAIL,
        ]);

        $wasExistingUser = $user->exists;
        $configuredPassword = $this->configuredPassword();
        $password = $configuredPassword ?? ($wasExistingUser ? null : Str::random(40));

        $user->fill([
            'name' => 'Admin',
            'is_active' => true,
        ]);

        $user->forceFill([
            'is_admin' => true,
            'role' => User::ROLE_SUPERADMIN,
        ]);

        if ($password !== null) {
            $user->password = $password;
        }

        $user->save();

        if (! $wasExistingUser && $configuredPassword === null && $this->command !== null) {
            $this->command->warn(sprintf(
                'AdminUserSeeder created %s with a generated password: %s',
                self::ADMIN_EMAIL,
                $password,
            ));
            $this->command->line(sprintf(
                'Set %s to control the local admin password explicitly.',
                self::PASSWORD_ENV,
            ));
        }
    }

    private function configuredPassword(): ?string
    {
        $password = env(self::PASSWORD_ENV);

        return filled($password) ? (string) $password : null;
    }
}
