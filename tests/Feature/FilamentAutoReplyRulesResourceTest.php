<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
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

    public function test_active_admin_can_open_auto_reply_rules_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->actingAs($admin)
            ->get(AutoReplyRuleResource::getUrl())
            ->assertOk()
            ->assertSee('Правила автоответа');
    }

    public function test_employee_auto_reply_rule_access_is_controlled_by_role_permission_matrix(): void
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
            ->assertSee('Правила автоответа');

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', AutoReplyRule::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $rule));
        $this->assertFalse(Gate::forUser($employee)->allows('create', AutoReplyRule::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $rule));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $rule));
    }

    public function test_employee_can_manage_auto_reply_rules_when_edit_and_delete_are_enabled_in_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.view', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.edit', true);
        $this->setRolePermission(User::ROLE_EMPLOYEE, 'auto_reply_rules.delete', true);

        Livewire::actingAs($employee)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => null,
                'reply_text' => 'Employee managed rule',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        Livewire::actingAs($employee)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => null,
                'reply_text' => 'Employee updated rule',
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $rule->refresh();

        $this->assertSame('Employee updated rule', $rule->reply_text);
        $this->assertFalse($rule->is_active);

        Livewire::actingAs($employee)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('delete', $rule)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($rule);
    }

    public function test_admin_can_create_edit_and_delete_auto_reply_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'contact_phone_condition' => null,
                'keyword' => 'Тест1',
                'reply_text' => 'Шаблон 1',
                'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame('Тест1', $rule->keyword);
        $this->assertSame('тест1', $rule->normalized_keyword);
        $this->assertSame(AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD, $rule->match_scope);
        $this->assertNull($rule->contact_phone_condition);
        $this->assertSame(AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE, $rule->telegram_button_type);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'contact_phone_condition' => null,
                'keyword' => 'Тест2',
                'reply_text' => 'Шаблон 2',
                'telegram_button_type' => null,
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $rule->refresh();

        $this->assertSame('Тест2', $rule->keyword);
        $this->assertSame('тест2', $rule->normalized_keyword);
        $this->assertSame('Шаблон 2', $rule->reply_text);
        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->telegram_button_type);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('delete', $rule)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($rule);
    }

    public function test_auto_reply_rules_table_uses_live_column_manager_and_icon_only_edit_action(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $rule = AutoReplyRule::factory()->create();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->assertTableActionHasIcon('edit', Heroicon::OutlinedPencilSquare, $rule)
            ->assertTableActionDoesNotHaveLabel('edit', $rule)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('match_scope')?->isToggleable());
                $this->assertTrue($table->getColumn('contact_phone_condition')?->isToggleable());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }

    public function test_normalized_keyword_must_be_unique_within_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => 'тест1',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => '  тест1  ',
                'reply_text' => 'Дубликат',
                'is_active' => true,
            ]);

        $this->assertSame(1, AutoReplyRule::query()->count());
    }

    public function test_admin_can_create_any_inbound_rule_without_keyword(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                'reply_text' => 'Поделитесь номером',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame(AutoReplyRule::MATCH_SCOPE_ANY_INBOUND, $rule->match_scope);
        $this->assertSame(AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE, $rule->contact_phone_condition);
        $this->assertNull($rule->keyword);
        $this->assertNull($rule->normalized_keyword);
    }

    public function test_admin_can_save_tag_effects_for_auto_reply_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $assignTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
            'is_active' => true,
        ]);
        $removeTag = Tag::factory()->create([
            'name' => 'Новый',
            'color' => Tag::COLOR_WARNING,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Подтверждаем тегирование',
                'assign_tag_ids' => [$assignTag->id],
                'remove_tag_ids' => [$removeTag->id],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertDatabaseHas('auto_reply_rule_tag_effects', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $assignTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);
        $this->assertDatabaseHas('auto_reply_rule_tag_effects', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $removeTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_REMOVE,
        ]);
    }

    public function test_admin_can_update_tag_effects_for_auto_reply_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $oldAssignTag = Tag::factory()->create([
            'name' => 'Старый assign',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $newAssignTag = Tag::factory()->create([
            'name' => 'Новый assign',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $removeTag = Tag::factory()->create([
            'name' => 'Снять метку',
            'color' => Tag::COLOR_DANGER,
        ]);

        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $oldAssignTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => null,
                'reply_text' => 'Обновили эффекты',
                'assign_tag_ids' => [$newAssignTag->id],
                'remove_tag_ids' => [$removeTag->id],
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseMissing('auto_reply_rule_tag_effects', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $oldAssignTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);
        $this->assertDatabaseHas('auto_reply_rule_tag_effects', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $newAssignTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);
        $this->assertDatabaseHas('auto_reply_rule_tag_effects', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $removeTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_REMOVE,
        ]);
    }

    public function test_same_tag_cannot_be_assigned_and_removed_in_same_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Конфликтный тег',
            'color' => Tag::COLOR_WARNING,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Конфликт',
                'assign_tag_ids' => [$tag->id],
                'remove_tag_ids' => [$tag->id],
                'is_active' => true,
            ]);

        $this->assertDatabaseCount('auto_reply_rules', 0);
        $this->assertDatabaseCount('auto_reply_rule_tag_effects', 0);
    }

    public function test_admin_can_create_contains_text_and_exact_parameter_rules(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                'keyword' => '  скидка  ',
                'reply_text' => 'Contains',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'keyword' => '  TEXT_1  ',
                'reply_text' => 'Parameter',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('auto_reply_rules', [
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
            'keyword' => 'скидка',
            'normalized_keyword' => 'скидка',
        ]);
        $this->assertDatabaseHas('auto_reply_rules', [
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
        ]);
    }

    public function test_admin_can_save_tag_conditions_for_auto_reply_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $requiredTag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
            'is_active' => true,
        ]);
        $excludedTag = Tag::factory()->create([
            'name' => 'Стоп',
            'color' => Tag::COLOR_DANGER,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Условие по тегам',
                'required_tag_ids' => [$requiredTag->id],
                'excluded_tag_ids' => [$excludedTag->id],
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertDatabaseHas('auto_reply_rule_tag_conditions', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $requiredTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);
        $this->assertDatabaseHas('auto_reply_rule_tag_conditions', [
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $excludedTag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_EXCLUDED,
        ]);
    }

    public function test_same_tag_cannot_be_required_and_excluded_in_same_rule(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Конфликтный тег условия',
            'color' => Tag::COLOR_WARNING,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Конфликт условий',
                'required_tag_ids' => [$tag->id],
                'excluded_tag_ids' => [$tag->id],
                'is_active' => true,
            ]);

        $this->assertDatabaseCount('auto_reply_rules', 0);
        $this->assertDatabaseCount('auto_reply_rule_tag_conditions', 0);
    }

    public function test_same_keyword_is_allowed_when_match_scope_differs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'keyword' => 'TEXT_1',
                'reply_text' => 'Parameter',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(2, AutoReplyRule::query()->count());
    }

    public function test_any_inbound_rule_must_be_unique_per_channel_and_phone_condition(): void
    {
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Первое правило',
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Дубликат',
            'is_active' => true,
        ]);
    }

    public function test_request_phone_button_cannot_be_saved_for_non_telegram_channel(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => 'тест1',
            'reply_text' => 'Шаблон 1',
            'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);
    }

    public function test_admin_can_save_request_phone_button_for_max_channel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', [
                'channel_id' => $channel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => 'Телефон',
                'reply_text' => 'Поделитесь номером',
                'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame(AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE, $rule->max_button_type);
        $this->assertNull($rule->telegram_button_type);
    }

    public function test_request_phone_button_cannot_be_saved_for_non_max_channel(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        $this->expectException(ValidationException::class);

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'keyword' => 'Телефон',
            'normalized_keyword' => 'телефон',
            'reply_text' => 'Поделитесь номером',
            'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
            'is_active' => true,
        ]);
    }

    private function setRolePermission(string $role, string $permissionKey, bool $granted): void
    {
        DB::table('role_permissions')
            ->where('role', $role)
            ->where('permission_key', $permissionKey)
            ->update(['granted' => $granted]);
    }
}
