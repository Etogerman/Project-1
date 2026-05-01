<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_user_seeder_creates_superadmin_account(): void
    {
        $this->seed(AdminUserSeeder::class);

        $user = User::query()
            ->where('email', 'admin@abrikosoff.local')
            ->firstOrFail();

        $this->assertTrue($user->is_active);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->isSuperadmin());
        $this->assertSame(User::ROLE_SUPERADMIN, $user->role);
    }

    public function test_admin_user_seeder_does_not_run_outside_local_or_testing(): void
    {
        $originalEnvironment = $this->app['env'];
        $this->app['env'] = 'production';

        try {
            (new AdminUserSeeder())->run();
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertDatabaseMissing('users', [
            'email' => 'admin@abrikosoff.local',
        ]);
    }

    public function test_admin_user_seeder_does_not_reset_existing_password_without_explicit_env_override(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@abrikosoff.local',
            'password' => 'existing-secret',
            'is_active' => false,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->seed(AdminUserSeeder::class);

        $user->refresh();

        $this->assertTrue(Hash::check('existing-secret', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->is_admin);
        $this->assertTrue($user->isSuperadmin());
        $this->assertSame(User::ROLE_SUPERADMIN, $user->role);
    }
}
