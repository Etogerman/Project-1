<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
