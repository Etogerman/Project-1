<?php

namespace Tests\Feature;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AutoReplyRuleMultichannelStageOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_stage_one_schema_adds_priority_and_pivot_table(): void
    {
        $this->assertTrue(Schema::hasColumns('auto_reply_rules', [
            'priority',
        ]));

        $this->assertTrue(Schema::hasColumns('auto_reply_rule_channels', [
            'auto_reply_rule_id',
            'channel_id',
            'button_type',
            'button_text',
            'button_url',
        ]));
    }

    public function test_stage_one_migration_backfills_pivot_for_existing_rule(): void
    {
        $migration = require database_path('migrations/2026_04_07_120000_add_priority_and_auto_reply_rule_channels_table.php');

        $migration->down();

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);

        $ruleId = DB::table('auto_reply_rules')->insertGetId([
            'channel_id' => $channel->id,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'contact_phone_condition' => null,
            'reply_text' => 'Reply text',
            'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
            'max_button_type' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertTrue(Schema::hasColumns('auto_reply_rules', [
            'priority',
        ]));

        $this->assertDatabaseHas('auto_reply_rules', [
            'id' => $ruleId,
            'priority' => 10,
        ]);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $ruleId,
            'channel_id' => $channel->id,
            'button_type' => 'share_contact',
            'button_text' => null,
            'button_url' => null,
        ]);
    }

    public function test_auto_reply_rule_and_channel_relations_are_wired_via_pivot(): void
    {
        $channels = Channel::factory()->count(2)->create([
            'is_active' => true,
        ]);

        $rule = AutoReplyRule::factory()
            ->forChannels($channels)
            ->create();

        $this->assertEqualsCanonicalizing(
            $channels->modelKeys(),
            $rule->load('channels')->channels->modelKeys(),
        );

        $this->assertTrue($channels[0]->fresh()->autoReplyRules->contains($rule));
        $this->assertTrue($channels[1]->fresh()->autoReplyRules->contains($rule));
    }

    public function test_factory_helpers_attach_channels_via_pivot_and_keep_legacy_channel_id(): void
    {
        $firstChannel = Channel::factory()->create([
            'is_active' => true,
        ]);
        $secondChannel = Channel::factory()->create([
            'is_active' => true,
        ]);

        $singleChannelRule = AutoReplyRule::factory()
            ->forChannel($firstChannel)
            ->create();

        $multiChannelRule = AutoReplyRule::factory()
            ->forChannels([$firstChannel, $secondChannel])
            ->create();

        $this->assertSame($firstChannel->id, $singleChannelRule->channel_id);
        $this->assertSame(10, $singleChannelRule->priority);
        $this->assertTrue($singleChannelRule->channel->is($firstChannel));

        $this->assertSame($firstChannel->id, $multiChannelRule->channel_id);
        $this->assertSame(10, $multiChannelRule->priority);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $singleChannelRule->id,
            'channel_id' => $firstChannel->id,
        ]);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $multiChannelRule->id,
            'channel_id' => $firstChannel->id,
        ]);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $multiChannelRule->id,
            'channel_id' => $secondChannel->id,
        ]);
    }

    public function test_stage_one_keeps_pivot_in_sync_for_rules_saved_after_migration(): void
    {
        $telegramChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ]);
        $maxChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'is_active' => true,
        ]);

        $rule = AutoReplyRule::query()->create([
            'channel_id' => $telegramChannel->id,
            'keyword' => 'TEXT_1',
            'normalized_keyword' => 'text_1',
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'contact_phone_condition' => null,
            'reply_text' => 'Reply text',
            'telegram_button_type' => AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE,
            'max_button_type' => null,
            'is_active' => true,
        ]);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $rule->id,
            'channel_id' => $telegramChannel->id,
            'button_type' => 'share_contact',
        ]);

        $rule->forceFill([
            'channel_id' => $maxChannel->id,
            'telegram_button_type' => null,
            'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
        ])->save();

        $this->assertDatabaseMissing('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $rule->id,
            'channel_id' => $telegramChannel->id,
        ]);

        $this->assertDatabaseHas('auto_reply_rule_channels', [
            'auto_reply_rule_id' => $rule->id,
            'channel_id' => $maxChannel->id,
            'button_type' => 'share_contact',
        ]);
    }

    public function test_relaxed_keyword_uniqueness_migration_down_stops_before_restoring_unique_constraint_with_duplicates(): void
    {
        $channel = Channel::factory()->create([
            'is_active' => true,
        ]);

        AutoReplyRule::factory()
            ->forChannel($channel)
            ->create([
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => 'ROLLBACK_KEY',
                'normalized_keyword' => AutoReplyRule::normalizeKeyword('ROLLBACK_KEY'),
                'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE,
                'reply_text' => 'Ответ для контакта с телефоном',
            ]);

        AutoReplyRule::factory()
            ->forChannel($channel)
            ->create([
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'keyword' => 'rollback_key',
                'normalized_keyword' => AutoReplyRule::normalizeKeyword('rollback_key'),
                'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                'reply_text' => 'Ответ для контакта без телефона',
            ]);

        $migration = require database_path('migrations/2026_04_29_000001_relax_auto_reply_rule_keyword_uniqueness.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot restore auto_reply_rules_channel_scope_keyword_unique');

        $migration->down();
    }
}
