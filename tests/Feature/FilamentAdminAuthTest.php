<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\AppVersion;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
        $loginAt = Carbon::parse('2026-05-09 11:20:00');

        Carbon::setTestNow($loginAt);

        Livewire::test(Login::class)
            ->fillForm([
                'email' => $user->email,
                'password' => 'password',
            ])
            ->call('authenticate')
            ->assertHasNoFormErrors()
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame($loginAt->toDateTimeString(), $user->refresh()->last_login_at?->toDateTimeString());

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

    public function test_global_search_is_disabled_in_admin_panel(): void
    {
        $this->assertNull(Filament::getPanel('admin')->getGlobalSearchProvider());
    }

    public function test_authenticated_admin_activity_is_tracked_with_five_minute_throttle(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'last_seen_at' => null,
        ]);
        $firstSeenAt = Carbon::parse('2026-05-09 12:00:00');

        Carbon::setTestNow($firstSeenAt);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->assertSame($firstSeenAt->toDateTimeString(), $user->refresh()->last_seen_at?->toDateTimeString());

        Carbon::setTestNow($firstSeenAt->copy()->addMinutes(4));

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->assertSame($firstSeenAt->toDateTimeString(), $user->refresh()->last_seen_at?->toDateTimeString());

        $nextSeenAt = $firstSeenAt->copy()->addMinutes(5);

        Carbon::setTestNow($nextSeenAt);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk();

        $this->assertSame($nextSeenAt->toDateTimeString(), $user->refresh()->last_seen_at?->toDateTimeString());
    }

    public function test_employee_dashboard_hides_system_navigation_items(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin')
            ->assertOk()
            ->assertSee('Контакты')
            ->assertSee('Диалоги')
            ->assertSee('Теги')
            ->assertDontSee('Каналы связи')
            ->assertDontSee('Правила автоответа')
            ->assertDontSee('Bitrix24')
            ->assertDontSee('Сотрудники');
    }

    public function test_not_found_page_has_link_to_admin_home(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/missing-page')
            ->assertNotFound()
            ->assertSee('Страница не найдена')
            ->assertSee('Перейти на главную')
            ->assertSee('data-theme', false)
            ->assertSee('localStorage.getItem(\'theme\')', false)
            ->assertSee(':root[data-theme="dark"]', false)
            ->assertSee(':root:not([data-theme="light"])', false)
            ->assertSee('href="'.url('/admin').'"', false);
    }

    public function test_forbidden_page_has_link_to_admin_home(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get('/admin/users')
            ->assertForbidden()
            ->assertSee('Доступ запрещён')
            ->assertSee('У вас нет доступа к этому разделу')
            ->assertSee('Перейти на главную')
            ->assertSee('data-theme', false)
            ->assertSee('localStorage.getItem(\'theme\')', false)
            ->assertSee(':root[data-theme="dark"]', false)
            ->assertSee(':root:not([data-theme="light"])', false)
            ->assertSee('href="'.url('/admin').'"', false);
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
