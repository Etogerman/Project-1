<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyCategories\AutoReplyCategoryResource;
use App\Filament\Resources\AutoReplyCategories\Pages\ManageAutoReplyCategories;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAutoReplyCategoriesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_auto_reply_categories_are_hidden_from_navigation(): void
    {
        $this->assertFalse(AutoReplyCategoryResource::shouldRegisterNavigation());
    }

    public function test_active_admin_can_open_auto_reply_categories_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(AutoReplyCategoryResource::getUrl())
            ->assertOk()
            ->assertSee('Категории автоответов');
    }

    public function test_employee_cannot_open_auto_reply_categories_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get(AutoReplyCategoryResource::getUrl())
            ->assertForbidden();
    }

    public function test_employee_with_auto_reply_view_can_open_auto_reply_categories_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'auto_reply_rules.view')
            ->update(['granted' => true]);

        $this->actingAs($employee->fresh())
            ->get(AutoReplyCategoryResource::getUrl())
            ->assertOk()
            ->assertSee('Категории автоответов');
    }

    public function test_auto_reply_category_policy_for_employee_uses_auto_reply_rule_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 0,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', [
                'auto_reply_rules.view',
                'auto_reply_rules.edit',
                'auto_reply_rules.delete',
            ])
            ->update(['granted' => true]);

        $employee = $employee->fresh();

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', AutoReplyCategory::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $category));
        $this->assertTrue(Gate::forUser($employee)->allows('create', AutoReplyCategory::class));
        $this->assertTrue(Gate::forUser($employee)->allows('update', $category));
        $this->assertTrue(Gate::forUser($employee)->allows('delete', $category));
    }

    public function test_auto_reply_category_permissions_respect_disabled_employee_matrix_values(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 0,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', [
                'auto_reply_rules.view',
                'auto_reply_rules.edit',
                'auto_reply_rules.delete',
            ])
            ->update(['granted' => false]);

        $employee = $employee->fresh();

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', AutoReplyCategory::class));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $category));
        $this->assertFalse(Gate::forUser($employee)->allows('create', AutoReplyCategory::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $category));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $category));
    }

    public function test_admin_can_create_edit_and_delete_unused_auto_reply_category(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->callAction('create', [
                'name' => 'Старт',
                'color' => 'ab_blue',
                'sort_order' => 10,
            ])
            ->assertHasNoFormErrors();

        $category = AutoReplyCategory::query()->firstOrFail();

        $this->assertSame('Старт', $category->name);
        $this->assertSame('primary', $category->color);
        $this->assertSame('ab_blue', $category->color_value);
        $this->assertSame(10, $category->sort_order);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->callTableAction('edit', $category, [
                'name' => 'Сбор контакта',
                'color' => '#1A2B3C',
                'sort_order' => 20,
            ])
            ->assertHasNoTableActionErrors();

        $category->refresh();

        $this->assertSame('Сбор контакта', $category->name);
        $this->assertSame('gray', $category->color);
        $this->assertSame('#1A2B3C', $category->color_value);
        $this->assertSame(20, $category->sort_order);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->assertTableActionVisible('delete', $category)
            ->callTableAction('delete', $category)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($category);
    }

    public function test_used_auto_reply_category_cannot_be_deleted_from_resource_table(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 0,
        ]);

        AutoReplyRule::factory()->create([
            'auto_reply_category_id' => $category->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->assertTableActionHidden('delete', $category);

        $this->assertDatabaseHas('auto_reply_categories', [
            'id' => $category->id,
        ]);
    }

    public function test_auto_reply_category_name_must_be_unique(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->callAction('create', [
                'name' => 'Старт',
                'sort_order' => 10,
            ])
            ->assertHasFormErrors(['name']);

        $this->assertDatabaseCount('auto_reply_categories', 1);
    }

    public function test_auto_reply_categories_table_shows_toggleable_id_column(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->tap(function ($component) use ($category): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('id')?->isToggleable());
                $this->assertSame($category->id, $table->getColumn('id')?->getStateFromRecord($category));
            });
    }
}
