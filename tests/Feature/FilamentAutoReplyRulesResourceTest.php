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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => null,
                'reply_text' => 'Employee managed rule',
                'is_active' => true,
            ]))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        Livewire::actingAs($employee)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => null,
                'reply_text' => 'Employee updated rule',
                'is_active' => false,
            ]))
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
            ->callAction('create', $this->buildRuleFormData(
                $channel,
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    'contact_phone_condition' => null,
                    'keyword' => 'Тест1',
                    'reply_text' => 'Шаблон 1',
                    'is_active' => true,
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame('Тест1', $rule->keyword);
        $this->assertSame('тест1', $rule->normalized_keyword);
        $this->assertSame(AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD, $rule->match_scope);
        $this->assertNull($rule->contact_phone_condition);
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($channel));

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, $this->buildRuleFormData(
                $channel,
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    'contact_phone_condition' => null,
                    'keyword' => 'Тест2',
                    'reply_text' => 'Шаблон 2',
                    'is_active' => false,
                ],
                [
                    'button_kind' => null,
                ],
            ))
            ->assertHasNoTableActionErrors();

        $rule->refresh();

        $this->assertSame('Тест2', $rule->keyword);
        $this->assertSame('тест2', $rule->normalized_keyword);
        $this->assertSame('Шаблон 2', $rule->reply_text);
        $this->assertFalse($rule->is_active);
        $this->assertNull($rule->getButtonTypeForChannel($channel));

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('delete', $rule)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($rule);
    }

    public function test_rule_without_name_uses_display_name_fallback(): void
    {
        $rule = AutoReplyRule::factory()->create([
            'name' => null,
        ]);

        $this->assertNull($rule->name);
        $this->assertSame("Автоответ #{$rule->id}", $rule->display_name);
    }

    public function test_rule_name_is_trimmed_to_null_when_it_is_blank(): void
    {
        $rule = AutoReplyRule::query()->create([
            'name' => '   ',
            'channel_id' => Channel::factory()->create()->id,
            'keyword' => 'Тест1',
            'normalized_keyword' => 'тест1',
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'reply_text' => 'Шаблон 1',
            'is_active' => true,
            'priority' => 10,
        ]);

        $this->assertNull($rule->fresh()->name);
        $this->assertSame("Автоответ #{$rule->id}", $rule->fresh()->display_name);
    }

    public function test_admin_can_create_rule_with_custom_name(): void
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'name' => 'Старт Telegram',
                'keyword' => 'Тест1',
                'reply_text' => 'Шаблон 1',
            ]))
            ->assertHasNoFormErrors()
            ->assertSee('Старт Telegram');

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame('Старт Telegram', $rule->name);
        $this->assertSame('Старт Telegram', $rule->display_name);
    }

    public function test_admin_can_create_rule_with_category(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $category = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData($channel, [
                'name' => 'Старт Telegram',
                'auto_reply_category_id' => $category->id,
                'keyword' => 'Тест1',
                'reply_text' => 'Шаблон 1',
            ]))
            ->assertHasNoFormErrors()
            ->assertSee('Старт');

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame($category->id, $rule->auto_reply_category_id);
    }

    public function test_admin_can_edit_rule_category(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $firstCategory = AutoReplyCategory::query()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);
        $secondCategory = AutoReplyCategory::query()->create([
            'name' => 'Fallback',
            'sort_order' => 20,
        ]);
        $rule = AutoReplyRule::factory()->create([
            'auto_reply_category_id' => $firstCategory->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, $this->buildRuleFormData($channel, [
                'name' => 'Старт Telegram',
                'auto_reply_category_id' => $secondCategory->id,
                'keyword' => 'Тест2',
                'reply_text' => 'Шаблон 2',
            ]))
            ->assertHasNoTableActionErrors();

        $this->assertSame($secondCategory->id, $rule->fresh()->auto_reply_category_id);
    }

    public function test_table_search_can_find_rule_by_name(): void
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

    public function test_table_filter_can_filter_rules_by_category(): void
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

    public function test_table_filter_can_filter_rules_without_category(): void
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

    public function test_admin_can_create_multichannel_rule_with_shared_request_phone_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Мульти',
                    'reply_text' => 'Общий ответ',
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail()->load('channels');

        $this->assertEqualsCanonicalizing(
            [$telegramChannel->id, $maxChannel->id],
            $rule->channels->modelKeys(),
        );
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($telegramChannel));
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($maxChannel));
    }

    public function test_admin_can_edit_multichannel_rule_with_shared_request_phone_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Мульти',
                    'reply_text' => 'Общий ответ',
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Мульти',
                    'reply_text' => 'Обновлённый ответ',
                    'is_active' => true,
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoTableActionErrors();

        $rule = $rule->fresh()->load('channels');

        $this->assertSame('Обновлённый ответ', $rule->reply_text);
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($telegramChannel));
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($maxChannel));
    }

    public function test_admin_can_disable_multichannel_rule_with_shared_request_phone_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Мульти',
                    'reply_text' => 'Общий ответ',
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Мульти',
                    'reply_text' => 'Общий ответ',
                    'is_active' => false,
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoTableActionErrors();

        $rule = $rule->fresh()->load('channels');

        $this->assertFalse($rule->is_active);
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($telegramChannel));
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($maxChannel));
    }

    public function test_admin_can_create_telegram_rule_with_shared_link_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                $telegramChannel,
                [
                    'keyword' => 'Ссылка',
                    'reply_text' => 'Перейдите по ссылке',
                ],
                [
                    'button_kind' => 'link',
                    'button_text' => 'Открыть форму',
                    'button_url' => 'https://example.com/form',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail()->load('channels');

        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $rule->getButtonTypeForChannel($telegramChannel));
        $this->assertSame('Открыть форму', $rule->getButtonTextForChannel($telegramChannel));
        $this->assertSame('https://example.com/form', $rule->getButtonUrlForChannel($telegramChannel));
    }

    public function test_admin_can_create_max_rule_with_shared_link_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                $maxChannel,
                [
                    'keyword' => 'MAX ссылка',
                    'reply_text' => 'Перейдите по ссылке',
                ],
                [
                    'button_kind' => 'link',
                    'button_text' => 'Открыть MAX форму',
                    'button_url' => 'https://example.com/max-form',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail()->load('channels');

        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $rule->getButtonTypeForChannel($maxChannel));
        $this->assertSame('Открыть MAX форму', $rule->getButtonTextForChannel($maxChannel));
        $this->assertSame('https://example.com/max-form', $rule->getButtonUrlForChannel($maxChannel));
    }

    public function test_admin_can_create_mixed_platform_rule_with_shared_link_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Telegram Sales',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$telegramChannel, $maxChannel],
                [
                    'keyword' => 'Общая ссылка',
                    'reply_text' => 'Перейдите по ссылке',
                ],
                [
                    'button_kind' => 'link',
                    'button_text' => 'Открыть заявку',
                    'button_url' => 'https://example.com/shared-form',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail()->load('channels');

        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $rule->getButtonTypeForChannel($telegramChannel));
        $this->assertSame(AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD, $rule->getButtonTypeForChannel($maxChannel));
        $this->assertSame('Открыть заявку', $rule->getButtonTextForChannel($telegramChannel));
        $this->assertSame('Открыть заявку', $rule->getButtonTextForChannel($maxChannel));
        $this->assertSame('https://example.com/shared-form', $rule->getButtonUrlForChannel($telegramChannel));
        $this->assertSame('https://example.com/shared-form', $rule->getButtonUrlForChannel($maxChannel));
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
            ->assertTableActionHasIcon('delete', Heroicon::OutlinedTrash, $rule)
            ->assertTableActionDoesNotHaveLabel('edit', $rule)
            ->assertTableActionDoesNotHaveLabel('delete', $rule)
            ->tap(function ($component): void {
                $table = $component->instance()->getTable();

                $this->assertTrue($table->hasColumnManager());
                $this->assertFalse($table->hasDeferredColumnManager());
                $this->assertFalse($table->getColumnManagerApplyAction()->isVisible());
                $this->assertTrue($table->getColumn('id')?->isToggleable());
                $this->assertTrue($table->getColumn('match_scope')?->isToggleable());
                $this->assertTrue($table->getColumn('contact_phone_condition')?->isToggleable());
                $this->assertSame('Кнопки', $table->getRecordActionsColumnLabel());
            });
    }

    public function test_normalized_keyword_duplicates_are_allowed_within_channel(): void
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => '  тест1  ',
                'reply_text' => 'Дубликат',
                'is_active' => true,
            ]));

        $this->assertSame(2, AutoReplyRule::query()->count());
    }

    public function test_exact_keyword_duplicate_is_allowed_for_overlapping_selected_channels(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $firstChannel = Channel::factory()->create(['is_active' => true]);
        $secondChannel = Channel::factory()->create(['is_active' => true]);
        $thirdChannel = Channel::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$firstChannel, $secondChannel],
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    'keyword' => 'OVERLAP',
                    'reply_text' => 'Первое правило',
                ],
            ))
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$secondChannel, $thirdChannel],
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    'keyword' => ' overlap ',
                    'reply_text' => 'Дубликат',
                ],
            ));

        $this->assertSame(2, AutoReplyRule::query()->count());
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                'reply_text' => 'Поделитесь номером',
                'is_active' => true,
            ]))
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Подтверждаем тегирование',
                'assign_tag_ids' => [$assignTag->id],
                'remove_tag_ids' => [$removeTag->id],
                'is_active' => true,
            ]))
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Конфликт',
                'assign_tag_ids' => [$tag->id],
                'remove_tag_ids' => [$tag->id],
                'is_active' => true,
            ]));

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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                'keyword' => '  скидка  ',
                'reply_text' => 'Contains',
                'is_active' => true,
            ]))
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'keyword' => '  TEXT_1  ',
                'reply_text' => 'Parameter',
                'is_active' => true,
            ]))
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

    public function test_admin_can_create_exact_text_or_parameter_rule(): void
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
                'keyword' => '  TEXT_OR_PARAM  ',
                'reply_text' => 'Combined',
                'is_active' => true,
            ]))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame(AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER, $rule->match_scope);
        $this->assertSame('TEXT_OR_PARAM', $rule->keyword);
        $this->assertSame('text_or_param', $rule->normalized_keyword);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->assertSee('Текст или параметр: TEXT_OR_PARAM');
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Условие по тегам',
                'required_tag_ids' => [$requiredTag->id],
                'excluded_tag_ids' => [$excludedTag->id],
                'is_active' => true,
            ]))
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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'reply_text' => 'Конфликт условий',
                'required_tag_ids' => [$tag->id],
                'excluded_tag_ids' => [$tag->id],
                'is_active' => true,
            ]));

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
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'keyword' => 'TEXT_1',
                'reply_text' => 'Parameter',
                'is_active' => true,
            ]))
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
                'keyword' => 'TEXT_1',
                'reply_text' => 'Combined',
                'is_active' => true,
            ]))
            ->assertHasNoFormErrors();

        $this->assertSame(3, AutoReplyRule::query()->count());
    }

    public function test_exact_text_or_parameter_duplicates_are_allowed_within_same_scope(): void
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
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
        ]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData($channel, [
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER,
                'keyword' => ' text_1 ',
                'reply_text' => 'Duplicate combined',
                'is_active' => true,
            ]));

        $this->assertSame(2, AutoReplyRule::query()->count());
    }

    public function test_any_inbound_duplicates_are_allowed_per_channel_and_phone_condition(): void
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

        AutoReplyRule::query()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
            'reply_text' => 'Дубликат',
            'is_active' => true,
        ]);

        $this->assertSame(2, AutoReplyRule::query()->count());
    }

    public function test_any_inbound_duplicate_is_allowed_for_overlapping_selected_channels(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $firstChannel = Channel::factory()->create(['is_active' => true]);
        $secondChannel = Channel::factory()->create(['is_active' => true]);
        $thirdChannel = Channel::factory()->create(['is_active' => true]);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$firstChannel, $secondChannel],
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                    'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                    'reply_text' => 'Первое any_inbound правило',
                ],
            ))
            ->assertHasNoFormErrors();

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callAction('create', $this->buildRuleFormData(
                [$secondChannel, $thirdChannel],
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                    'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                    'reply_text' => 'Дубликат any_inbound',
                ],
            ));

        $this->assertSame(2, AutoReplyRule::query()->count());
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
            ->callAction('create', $this->buildRuleFormData(
                $channel,
                [
                    'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                    'keyword' => 'Телефон',
                    'reply_text' => 'Поделитесь номером',
                    'is_active' => true,
                ],
                [
                    'button_kind' => 'request_phone',
                ],
            ))
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame(AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT, $rule->getButtonTypeForChannel($channel));
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

    /**
     * @param  Channel|array<int, Channel>  $channels
     * @param  array<string, mixed>  $overrides
     * @param  array<string, mixed>  $buttonOverrides
     * @return array<string, mixed>
     */
    private function buildRuleFormData(Channel|array $channels, array $overrides = [], array $buttonOverrides = []): array
    {
        $channelList = array_values(is_array($channels) ? $channels : [$channels]);

        return array_merge([
            'name' => null,
            'auto_reply_category_id' => null,
            'channel_ids' => array_map(
                fn (Channel $channel): int => (int) $channel->id,
                $channelList,
            ),
            'button_kind' => null,
            'button_text' => null,
            'button_url' => null,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'contact_phone_condition' => null,
            'reply_text' => 'Тестовый автоответ',
            'is_active' => true,
            'priority' => 10,
        ], $buttonOverrides, $overrides);
    }
}
