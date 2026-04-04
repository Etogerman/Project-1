<?php

namespace Tests\Feature;

use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentTagsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_tags_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(TagResource::getUrl())
            ->assertOk()
            ->assertSee('Теги')
            ->assertSee('ac-inline-list-page', false)
            ->assertSee('ac-list-page-header-toolbar', false);
    }

    public function test_employee_cannot_open_tags_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($employee)
            ->get(TagResource::getUrl())
            ->assertForbidden();
    }

    public function test_admin_can_create_edit_and_delete_unused_tag(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->callAction('create', [
                'name' => 'VIP',
                'color' => Tag::COLOR_SUCCESS,
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $tag = Tag::query()->firstOrFail();

        $this->assertSame('VIP', $tag->name);
        $this->assertSame('vip', $tag->slug);
        $this->assertSame(Tag::COLOR_SUCCESS, $tag->color);
        $this->assertTrue($tag->is_active);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->callTableAction('edit', $tag, [
                'name' => 'Повторный клиент',
                'color' => Tag::COLOR_WARNING,
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $tag->refresh();

        $this->assertSame('Повторный клиент', $tag->name);
        $this->assertSame('povtornyy-klient', $tag->slug);
        $this->assertSame(Tag::COLOR_WARNING, $tag->color);
        $this->assertFalse($tag->is_active);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->assertTableActionVisible('delete', $tag)
            ->callTableAction('delete', $tag)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($tag);
    }

    public function test_admin_can_open_create_tag_modal_with_visible_color_choices(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->mountAction('create')
            ->assertMountedActionModalSee('Название')
            ->assertMountedActionModalSee('Цвет')
            ->assertMountedActionModalSee('Серый')
            ->assertMountedActionModalSee('Синий')
            ->assertMountedActionModalSee('Зелёный')
            ->assertMountedActionModalSee('Жёлтый')
            ->assertMountedActionModalSee('Красный')
            ->assertMountedActionModalSee('Активен');
    }

    public function test_tags_table_uses_inline_list_page_standard(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $tag = Tag::factory()->create();

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->assertTableActionHasIcon('edit', Heroicon::OutlinedPencilSquare, $tag)
            ->assertTableActionHasIcon('delete', Heroicon::OutlinedTrash, $tag)
            ->assertTableActionDoesNotHaveLabel('edit', $tag)
            ->assertTableActionDoesNotHaveLabel('delete', $tag)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertContains('ac-inline-list-page', $component->instance()->getPageClasses());
                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('id')?->isToggleable());
                $this->assertTrue($table->getColumn('slug')?->isToggleable());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }

    public function test_used_tag_cannot_be_deleted_from_resource_table(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create();
        $tag = Tag::factory()->create([
            'name' => 'В работе',
            'color' => Tag::COLOR_PRIMARY,
        ]);

        $contact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->assertTableActionHidden('delete', $tag);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
        ]);
    }
}
