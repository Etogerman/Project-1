<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyCategories\AutoReplyCategoryResource;
use App\Filament\Resources\AutoReplyCategories\Pages\ManageAutoReplyCategories;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        ]);

        $this->actingAs($employee)
            ->get(AutoReplyCategoryResource::getUrl())
            ->assertForbidden();
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
                'sort_order' => 10,
            ])
            ->assertHasNoFormErrors();

        $category = AutoReplyCategory::query()->firstOrFail();

        $this->assertSame('Старт', $category->name);
        $this->assertSame(10, $category->sort_order);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyCategories::class)
            ->callTableAction('edit', $category, [
                'name' => 'Сбор контакта',
                'sort_order' => 20,
            ])
            ->assertHasNoTableActionErrors();

        $category->refresh();

        $this->assertSame('Сбор контакта', $category->name);
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
