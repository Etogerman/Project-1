<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\ManageContacts;
use App\Filament\Resources\Tags\Pages\ManageTags;
use App\Filament\Resources\Tags\TagResource;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            ->assertSee('Теги');
    }

    public function test_inactive_employee_cannot_open_tags_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => false,
            'is_admin' => false,
        ]);

        $this->actingAs($employee)
            ->get(TagResource::getUrl())
            ->assertForbidden();
    }

    public function test_employee_with_tags_view_can_open_tags_page(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get(TagResource::getUrl())
            ->assertOk()
            ->assertSee('Теги');
    }

    public function test_tag_policy_for_employee_uses_role_permission_matrix(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $tag = Tag::factory()->create();

        $this->assertTrue(Gate::forUser($employee)->allows('viewAny', Tag::class));
        $this->assertTrue(Gate::forUser($employee)->allows('view', $tag));
        $this->assertTrue(Gate::forUser($employee)->allows('create', Tag::class));
        $this->assertTrue(Gate::forUser($employee)->allows('update', $tag));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $tag));
    }

    public function test_tags_permissions_respect_disabled_employee_matrix_values(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $tag = Tag::factory()->create();

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->whereIn('permission_key', ['tags.view', 'tags.edit', 'tags.delete'])
            ->update(['granted' => false]);

        $employee = User::query()->findOrFail($employee->id);

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', Tag::class));
        $this->assertFalse(Gate::forUser($employee)->allows('view', $tag));
        $this->assertFalse(Gate::forUser($employee)->allows('create', Tag::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $tag));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $tag));
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
            ->assertMountedActionModalSee('Тег активный');
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

    public function test_tags_table_shows_contacts_and_unique_rules_usage_counts_with_links(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $tag = Tag::factory()->create([
            'name' => 'VIP',
            'color' => Tag::COLOR_SUCCESS,
        ]);
        $otherTag = Tag::factory()->create([
            'name' => 'Другое',
            'color' => Tag::COLOR_WARNING,
        ]);
        $firstContact = Contact::factory()->create();
        $secondContact = Contact::factory()->create();

        $firstContact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondContact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $firstRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
        ]);
        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'VIP',
            'normalized_keyword' => 'vip',
        ]);

        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $firstRule->id,
            'tag_id' => $tag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);
        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $firstRule->id,
            'tag_id' => $tag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);
        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $secondRule->id,
            'tag_id' => $tag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_EXCLUDED,
        ]);
        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $secondRule->id,
            'tag_id' => $otherTag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->assertTableColumnExists(
                'contacts_count',
                fn (TextColumn $column): bool => $column->getLabel() === 'Контакты'
                    && $column->getUrl() === ContactResource::getUrl(parameters: ['tag' => $tag->id]),
                $tag,
            )
            ->assertTableColumnExists(
                'used_in_rules_count',
                fn (TextColumn $column): bool => $column->getLabel() === 'Используют'
                    && $column->getUrl() === AutoReplyRuleResource::getUrl(parameters: ['tag' => $tag->id]),
                $tag,
            )
            ->assertTableColumnStateSet('contacts_count', 2, $tag)
            ->assertTableColumnStateSet('used_in_rules_count', 2, $tag)
            ->assertTableColumnStateSet('used_in_rules_count', 1, $otherTag);
    }

    public function test_tag_link_opens_filtered_contacts_list(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $tag = Tag::factory()->create();
        $matchingContact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();

        $matchingContact->tags()->attach($tag->id, [
            'assigned_at' => now(),
            'assigned_by_user_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Livewire::withQueryParams(['tag' => $tag->id])
            ->actingAs($admin)
            ->test(ManageContacts::class)
            ->assertSet('tableFilters.tags.values.0', (string) $tag->id)
            ->assertCanSeeTableRecords([$matchingContact])
            ->assertCanNotSeeTableRecords([$otherContact]);
    }

    public function test_tag_link_opens_filtered_auto_reply_rules_list(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $tag = Tag::factory()->create();
        $matchingRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
        ]);
        $otherRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
        ]);

        AutoReplyRuleTagEffect::query()->create([
            'auto_reply_rule_id' => $matchingRule->id,
            'tag_id' => $tag->id,
            'effect' => AutoReplyRuleTagEffect::EFFECT_ASSIGN,
        ]);

        Livewire::withQueryParams(['tag' => $tag->id])
            ->actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->assertSet('tableFilters.tag.value', (string) $tag->id)
            ->assertCanSeeTableRecords([$matchingRule])
            ->assertCanNotSeeTableRecords([$otherRule]);
    }

    public function test_tag_used_in_auto_reply_rules_cannot_be_deleted_from_resource_table(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $tag = Tag::factory()->create([
            'name' => 'Используемый тег',
            'color' => Tag::COLOR_PRIMARY,
        ]);
        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
        ]);

        AutoReplyRuleTagCondition::query()->create([
            'auto_reply_rule_id' => $rule->id,
            'tag_id' => $tag->id,
            'condition' => AutoReplyRuleTagCondition::CONDITION_REQUIRED,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageTags::class)
            ->assertTableActionHidden('delete', $tag);

        $this->assertDatabaseHas('tags', [
            'id' => $tag->id,
        ]);
    }
}
