<?php

namespace Tests\Feature;

use App\Support\AppVersion;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAdminAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_guest_is_redirected_to_the_filament_login_page(): void
    {
        $this->get('/admin')
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSee('Войдите в свой аккаунт');
    }

    public function test_active_non_admin_user_can_log_in_to_the_admin_panel(): void
    {
        $user = User::factory()->create([
            'email' => 'active@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get('/admin')
            ->assertOk();
    }

    public function test_authenticated_user_sees_current_version_badge_in_admin_panel(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $version = AppVersion::display();

        $this->assertNotNull($version);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee($version);
    }

    public function test_inactive_user_cannot_log_in_to_the_admin_panel(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'is_active' => false,
            'is_admin' => false,
        ]);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasErrors(['data.email']);

        $this->assertGuest();

        $this->get(route('filament.admin.auth.login'))
            ->assertOk();
    }

    public function test_authenticated_user_can_log_out_from_the_admin_panel(): void
    {
        $user = User::factory()->create([
            'email' => 'logout@example.com',
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirect(route('filament.admin.auth.login'));

        $this->assertGuest();
    }
}
