<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
