<?php

namespace Tests\Feature;

use App\Filament\Resources\DialogStages\DialogStageResource;
use App\Filament\Resources\DialogStages\Pages\ManageDialogStages;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDialogStagesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_dialog_stages_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(DialogStageResource::getUrl())
            ->assertOk()
            ->assertSee('Стадии диалогов');
    }

    public function test_employee_cannot_open_dialog_stages_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get(DialogStageResource::getUrl())
            ->assertForbidden();
    }

    public function test_dialog_stage_policy_uses_system_management_role(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $stage = DialogStage::query()->where('key', DialogStage::KEY_TRANSFERRED_TO_MPL)->firstOrFail();

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', DialogStage::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', DialogStage::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $stage));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $stage));

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', DialogStage::class));
        $this->assertFalse(Gate::forUser($employee)->allows('create', DialogStage::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $stage));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $stage));
    }

    public function test_admin_can_create_and_edit_dialog_stage_without_changing_key(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->callAction('create', [
                'name' => 'Дожим оператором',
                'color' => DialogStage::COLOR_WARNING,
                'sort_order' => 60,
            ])
            ->assertHasNoFormErrors();

        $stage = DialogStage::query()->where('name', 'Дожим оператором')->firstOrFail();

        $this->assertSame('dozhim_operatorom', $stage->key);
        $this->assertSame(DialogStage::COLOR_WARNING, $stage->color);
        $this->assertSame(60, $stage->sort_order);
        $this->assertNull($stage->system_role);

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->callTableAction('edit', $stage, [
                'name' => 'Возврат к оператору',
                'color' => DialogStage::COLOR_SUCCESS,
                'sort_order' => 70,
            ])
            ->assertHasNoTableActionErrors();

        $stage->refresh();

        $this->assertSame('Возврат к оператору', $stage->name);
        $this->assertSame('dozhim_operatorom', $stage->key);
        $this->assertSame(DialogStage::COLOR_SUCCESS, $stage->color);
        $this->assertSame(70, $stage->sort_order);
    }

    public function test_stage_key_cannot_be_changed_directly_after_creation(): void
    {
        $stage = DialogStage::factory()->create([
            'key' => 'operator_follow_up',
            'name' => 'Дожим оператором',
        ]);

        $stage->key = 'new_key';

        $this->expectException(ValidationException::class);

        $stage->save();
    }

    public function test_admin_can_open_create_stage_modal_with_visible_color_palette(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->mountAction('create')
            ->assertMountedActionModalSee('Цвет')
            ->assertMountedActionModalSee('Серый')
            ->assertMountedActionModalSee('Голубой')
            ->assertMountedActionModalSee('Синий')
            ->assertMountedActionModalSee('Зелёный')
            ->assertMountedActionModalSee('Жёлтый')
            ->assertMountedActionModalSee('Красный')
            ->assertMountedActionModalSeeHtml('ac-color-picker')
            ->assertMountedActionModalSeeHtml('ac-color-picker__swatch')
            ->assertMountedActionModalSeeHtml('#0099FF');
    }

    public function test_color_picker_uses_single_hidden_form_state_input(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $stage = DialogStage::query()->where('key', DialogStage::KEY_TRANSFERRED_TO_MPP)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->mountTableAction('edit', $stage)
            ->assertMountedActionModalSeeHtml('x-ref="stateInput"')
            ->assertMountedActionModalSeeHtml('type="hidden"')
            ->assertMountedActionModalDontSeeHtml('pattern="#?[0-9A-Fa-f]{6}"');
    }

    public function test_dialog_stage_table_renders_actual_color_chip(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $stage = DialogStage::query()->where('key', DialogStage::KEY_NEW_DIALOG)->firstOrFail();

        $stage->update(['color' => 'ab_navy']);

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->assertSee('Тёмно-синий')
            ->assertSeeHtml('data-color-chip')
            ->assertSeeHtml('data-color-value="ab_navy"')
            ->assertSeeHtml('data-color-hex="#003399"')
            ->assertSeeHtml('background:#003399')
            ->assertSeeHtml('color:#FFFFFF');
    }

    public function test_automatic_system_stage_cannot_be_deleted_from_resource(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $stage = DialogStage::query()->where('key', DialogStage::KEY_NEW_DIALOG)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->assertTableActionHidden('delete', $stage);

        $this->assertDatabaseHas('dialog_stages', [
            'id' => $stage->id,
        ]);
    }

    public function test_admin_can_delete_manual_stage_with_dialog_replacement(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $sourceStage = DialogStage::factory()->create([
            'key' => 'operator_follow_up',
            'name' => 'Дожим оператором',
            'sort_order' => 60,
            'system_role' => null,
        ]);
        $replacementStage = DialogStage::query()
            ->where('key', DialogStage::KEY_TRANSFERRED_TO_MPP)
            ->firstOrFail();
        $dialog = Dialog::factory()->create([
            'contact_id' => Contact::factory(),
            'channel_id' => Channel::factory(),
            'stage' => $sourceStage->key,
            'stage_id' => $sourceStage->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->assertTableActionVisible('delete', $sourceStage)
            ->callTableAction('delete', $sourceStage, [
                'replacement_stage_id' => $replacementStage->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($sourceStage);

        $dialog->refresh();

        $this->assertSame($replacementStage->id, $dialog->stage_id);
        $this->assertSame($replacementStage->key, $dialog->stage);
    }

    public function test_dialog_stages_table_uses_inline_list_page_standard(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $stage = DialogStage::query()->where('key', DialogStage::KEY_TRANSFERRED_TO_MPL)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageDialogStages::class)
            ->assertTableActionHasIcon('edit', Heroicon::OutlinedPencilSquare, $stage)
            ->assertTableActionHasIcon('delete', Heroicon::OutlinedTrash, $stage)
            ->assertTableActionDoesNotHaveLabel('edit', $stage)
            ->assertTableActionDoesNotHaveLabel('delete', $stage)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('key')?->isToggleable());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }
}
