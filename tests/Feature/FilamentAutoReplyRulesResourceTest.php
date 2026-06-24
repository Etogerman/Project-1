<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentAutoReplyRulesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_auto_reply_rules_archive_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(AutoReplyRuleResource::getUrl())
            ->assertOk()
            ->assertSee('Архив старых автоответов')
            ->assertSee('Не используется после перехода на V3-конструктор')
            ->assertSee('Открыть V3 автоответчик')
            ->assertSee('Экспорт в XLSX');
    }

    public function test_employee_auto_reply_rule_archive_access_is_controlled_by_view_permission(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $rule = AutoReplyRule::factory()->create();

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.edit', false);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.delete', false);

        $this->actingAs($employee)
            ->get(AutoReplyRuleResource::getUrl())
            ->assertOk()
            ->assertSee('Архив старых автоответов');

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', AutoReplyRule::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $rule));
        $this->assertFalse(Gate::forUser($employee)->allows('create', AutoReplyRule::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $rule));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $rule));
    }

    public function test_archive_is_read_only_even_when_user_has_legacy_manage_permissions(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $rule = AutoReplyRule::factory()->create();

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.edit', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.delete', true);

        Livewire::actingAs($employee)
            ->test(ManageAutoReplyRules::class)
            ->assertActionVisible('openV3AutoReplyConstructor')
            ->assertActionVisible('exportWorkbook')
            ->assertActionDoesNotExist('create')
            ->assertActionDoesNotExist('importWorkbook')
            ->assertActionDoesNotExist('applyWorkbookImport')
            ->assertTableActionDoesNotExist('edit', null, $rule)
            ->assertTableActionDoesNotExist('delete', null, $rule);
    }

    public function test_auto_reply_rules_archive_keeps_table_tools(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('id')?->isToggleable());
                $this->assertTrue($table->getColumn('match_scope')?->isToggleable());
                $this->assertTrue($table->getColumn('contact_phone_condition')?->isToggleable());
                $this->assertNotNull($table->getFilter('auto_reply_category_id'));
                $this->assertNotNull($table->getFilter('channel_id'));
                $this->assertNotNull($table->getFilter('tag'));
                $this->assertNotNull($table->getFilter('is_active'));
            });
    }

    public function test_table_search_can_find_archived_rule_by_name(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $namedRule = AutoReplyRule::factory()->create([
            'name' => 'VIP fallback',
        ]);
        $otherRule = AutoReplyRule::factory()->create([
            'name' => 'Другой автоответ',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->searchTable('VIP fallback')
            ->assertCanSeeTableRecords([$namedRule])
            ->assertCanNotSeeTableRecords([$otherRule]);
    }

    public function test_table_filters_can_filter_archived_rules_by_category(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $matchingCategory = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);
        $otherCategory = AutoReplyCategory::query()->create([
            'name' => 'Fallback',
            'sort_order' => 20,
        ]);
        $matchingRule = AutoReplyRule::factory()->create([
            'auto_reply_category_id' => $matchingCategory->id,
        ]);
        $otherRule = AutoReplyRule::factory()->create([
            'auto_reply_category_id' => $otherCategory->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->filterTable('auto_reply_category_id', $matchingCategory->id)
            ->assertCanSeeTableRecords([$matchingRule])
            ->assertCanNotSeeTableRecords([$otherRule]);
    }

    public function test_category_filter_options_preserve_real_category_ids(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $trainingCategory = AutoReplyCategory::query()->create([
            'name' => 'Тренинги',
            'sort_order' => 10,
        ]);
        $huntingCategory = AutoReplyCategory::query()->create([
            'name' => 'Охота',
            'sort_order' => 20,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->tap(function ($component) use ($trainingCategory, $huntingCategory): void {
                $filter = $component->instance()->getTable()->getFilter('auto_reply_category_id');

                $this->assertSame([
                    '__without_category__' => 'Без категории',
                    $trainingCategory->id => 'Тренинги',
                    $huntingCategory->id => 'Охота',
                ], $filter?->getOptions());
            });
    }

    public function test_table_filters_can_filter_archived_rules_without_category(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);
        $withoutCategoryRule = AutoReplyRule::factory()->create([
            'auto_reply_category_id' => null,
        ]);
        $categorizedRule = AutoReplyRule::factory()->create([
            'auto_reply_category_id' => $category->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->filterTable('auto_reply_category_id', '__without_category__')
            ->assertCanSeeTableRecords([$withoutCategoryRule])
            ->assertCanNotSeeTableRecords([$categorizedRule]);
    }

    public function test_table_filters_can_filter_archived_rules_by_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $matchingChannel = Channel::factory()->create([
            'name' => 'Telegram Local',
            'is_active' => true,
        ]);
        $otherChannel = Channel::factory()->create([
            'name' => 'MAX Local',
            'is_active' => true,
        ]);
        $matchingRule = AutoReplyRule::factory()->forChannel($matchingChannel)->create();
        $otherRule = AutoReplyRule::factory()->forChannel($otherChannel)->create();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->filterTable('channel_id', $matchingChannel->id)
            ->assertCanSeeTableRecords([$matchingRule])
            ->assertCanNotSeeTableRecords([$otherRule]);
    }

    public function test_table_filters_can_filter_archived_rules_by_tag(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $matchingTag = Tag::factory()->create([
            'name' => 'VIP',
        ]);
        $otherTag = Tag::factory()->create([
            'name' => 'Other',
        ]);
        $matchingByEffectRule = AutoReplyRule::factory()->create();
        $matchingByConditionRule = AutoReplyRule::factory()->create();
        $otherRule = AutoReplyRule::factory()->create();

        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $matchingByEffectRule->id,
            'tag_id' => $matchingTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);
        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $matchingByConditionRule->id,
            'tag_id' => $matchingTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $otherRule->id,
            'tag_id' => $otherTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->filterTable('tag', $matchingTag->id)
            ->assertCanSeeTableRecords([$matchingByEffectRule, $matchingByConditionRule])
            ->assertCanNotSeeTableRecords([$otherRule]);
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }
}
