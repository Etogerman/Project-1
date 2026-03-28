<?php

namespace Tests\Feature;

use App\Filament\Resources\AutoReplyRules\AutoReplyRuleResource;
use App\Filament\Resources\AutoReplyRules\Pages\ManageAutoReplyRules;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
                'keyword' => 'Тест1',
                'reply_text' => 'Шаблон 1',
                'is_active' => true,
            ])
            ->assertHasNoFormErrors();

        $rule = AutoReplyRule::query()->firstOrFail();

        $this->assertSame('Тест1', $rule->keyword);
        $this->assertSame('тест1', $rule->normalized_keyword);

        Livewire::actingAs($admin)
            ->test(ManageAutoReplyRules::class)
            ->callTableAction('edit', $rule, [
                'channel_id' => $channel->id,
                'keyword' => 'Тест2',
                'reply_text' => 'Шаблон 2',
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $rule->refresh();

        $this->assertSame('Тест2', $rule->keyword);
        $this->assertSame('тест2', $rule->normalized_keyword);
        $this->assertSame('Шаблон 2', $rule->reply_text);
        $this->assertFalse($rule->is_active);

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
                'keyword' => '  тест1  ',
                'reply_text' => 'Дубликат',
                'is_active' => true,
            ]);

        $this->assertSame(1, AutoReplyRule::query()->count());
    }
}
