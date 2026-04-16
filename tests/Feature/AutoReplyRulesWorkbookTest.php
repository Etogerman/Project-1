<?php

namespace Tests\Feature;

use App\Data\AutoReplyRules\AutoReplyRuleWorkbookPreviewData;
use App\Models\AutoReplyCategory;
use App\Models\AutoReplyRule;
use App\Models\AutoReplyRuleTagCondition;
use App\Models\AutoReplyRuleTagEffect;
use App\Models\Channel;
use App\Models\Tag;
use App\Services\AutoReplyRules\ApplyAutoReplyRulesWorkbookImportAction;
use App\Services\AutoReplyRules\AutoReplyRuleWorkbookFormat;
use App\Services\AutoReplyRules\ExportAutoReplyRulesWorkbookAction;
use App\Services\AutoReplyRules\ParseAutoReplyRulesWorkbookAction;
use App\Services\Bots\SyncAutoReplyRuleTagConditionsAction;
use App\Services\Bots\SyncAutoReplyRuleTagEffectsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class AutoReplyRulesWorkbookTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_workbook_contains_expected_rule_data(): void
    {
        $category = AutoReplyCategory::factory()->create([
            'name' => 'Старт',
            'sort_order' => 10,
        ]);
        $telegramChannel = Channel::factory()->create([
            'name' => 'Продажи',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'Поддержка',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $requiredTag = Tag::factory()->create(['name' => 'VIP']);
        $excludedTag = Tag::factory()->create(['name' => 'Стоп']);
        $assignTag = Tag::factory()->create(['name' => 'Новичок']);
        $removeTag = Tag::factory()->create(['name' => 'Старый']);

        $rule = AutoReplyRule::factory()
            ->forChannels([$telegramChannel, $maxChannel])
            ->create([
                'name' => 'Старт Telegram',
                'auto_reply_category_id' => $category->id,
                'channel_id' => $telegramChannel->id,
                'keyword' => '/start',
                'normalized_keyword' => AutoReplyRule::normalizeKeyword('/start'),
                'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER,
                'reply_text' => 'Привет!',
                'priority' => 5,
            ]);

        $rule->channels()->sync([
            $telegramChannel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
                'button_text' => 'Оставить заявку',
                'button_url' => 'https://example.com',
            ],
            $maxChannel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD,
                'button_text' => 'Оставить заявку',
                'button_url' => 'https://example.com',
            ],
        ]);

        app(SyncAutoReplyRuleTagConditionsAction::class)->handle($rule, [$requiredTag->id], [$excludedTag->id]);
        app(SyncAutoReplyRuleTagEffectsAction::class)->handle($rule, [$assignTag->id], [$removeTag->id]);

        $spreadsheet = app(ExportAutoReplyRulesWorkbookAction::class)->handle();

        try {
            $this->assertSame([
                AutoReplyRuleWorkbookFormat::SHEET_RULES,
                AutoReplyRuleWorkbookFormat::SHEET_CATEGORIES,
                AutoReplyRuleWorkbookFormat::SHEET_CHANNELS,
                AutoReplyRuleWorkbookFormat::SHEET_TAGS,
                AutoReplyRuleWorkbookFormat::SHEET_INSTRUCTIONS,
            ], $spreadsheet->getSheetNames());

            $rows = $spreadsheet
                ->getSheetByName(AutoReplyRuleWorkbookFormat::SHEET_RULES)
                ?->toArray(null, true, true, false);

            $this->assertNotNull($rows);
            $this->assertSame(AutoReplyRuleWorkbookFormat::rulesColumns(), $rows[0]);
            $this->assertSame((string) $rule->id, (string) $rows[1][0]);
            $this->assertSame('Старт Telegram', $rows[1][1]);
            $this->assertSame('Старт', $rows[1][2]);
            $this->assertSame('link', $rows[1][9]);
            $this->assertSame('Оставить заявку', $rows[1][10]);
            $this->assertSame('https://example.com', $rows[1][11]);
            $this->assertSame($telegramChannel->id.';'.$maxChannel->id, $rows[1][12]);
            $this->assertSame('VIP', $rows[1][13]);
            $this->assertSame('Стоп', $rows[1][14]);
            $this->assertSame('Новичок', $rows[1][15]);
            $this->assertSame('Старый', $rows[1][16]);
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    public function test_parse_preview_reports_unknown_category_error(): void
    {
        $channel = Channel::factory()->create();

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое правило',
                'Несуществующая категория',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'hello',
                '',
                'Привет',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertSame(0, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame('category_name', $preview->errors[0]->column);
    }

    public function test_parse_preview_reports_existing_rule_uniqueness_conflict_for_create_row(): void
    {
        $channel = Channel::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'ONASA01',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('ONASA01'),
            'reply_text' => 'Existing reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'ONASA01',
                '',
                'Импортируемый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertSame(0, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame('id', $preview->errors[0]->column);
        $this->assertStringContainsString('Укажите id для обновления', $preview->errors[0]->message);
    }

    public function test_parse_preview_reports_existing_rule_uniqueness_conflict_for_create_row_with_zero_keyword(): void
    {
        $channel = Channel::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => '0',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('0'),
            'reply_text' => 'Existing reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                '0',
                '',
                'Импортируемый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertSame(0, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame('id', $preview->errors[0]->column);
        $this->assertStringContainsString('Укажите id для обновления', $preview->errors[0]->message);
    }

    public function test_parse_preview_reports_duplicate_rule_key_inside_workbook(): void
    {
        $channel = Channel::factory()->create();

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Первое правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'ONASA01',
                '',
                'Первый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
            [
                '',
                'Второе правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'ONASA01',
                '',
                'Второй ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertSame(1, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame(3, $preview->errors[0]->rowNumber);
        $this->assertSame('keyword', $preview->errors[0]->column);
        $this->assertStringContainsString('Строка конфликтует со строкой 2', $preview->errors[0]->message);
    }

    public function test_parse_preview_allows_duplicate_updates_of_same_rule_with_same_future_key(): void
    {
        $channel = Channel::factory()->create();
        $rule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'OLD_KEY',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('OLD_KEY'),
            'reply_text' => 'Existing reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                (string) $rule->id,
                'Первое обновление',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'NEW_KEY',
                '',
                'Первый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
            [
                (string) $rule->id,
                'Второе обновление',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'NEW_KEY',
                '',
                'Второй ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(0, $preview->createCount());
        $this->assertSame(2, $preview->updateCount());
    }

    public function test_import_allows_key_handoff_between_existing_rules_in_row_order(): void
    {
        $channel = Channel::factory()->create();
        $firstRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'KEY_A',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('KEY_A'),
            'reply_text' => 'First reply',
            'is_active' => true,
            'priority' => 10,
        ]);
        $secondRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'KEY_C',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('KEY_C'),
            'reply_text' => 'Second reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                (string) $firstRule->id,
                'Первое правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'KEY_B',
                '',
                'Первый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
            [
                (string) $secondRule->id,
                'Второе правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'KEY_A',
                '',
                'Второй ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(0, $preview->createCount());
        $this->assertSame(2, $preview->updateCount());

        app(ApplyAutoReplyRulesWorkbookImportAction::class)->handle($preview);

        $firstRule->refresh();
        $secondRule->refresh();

        $this->assertSame('KEY_B', $firstRule->keyword);
        $this->assertSame('KEY_A', $secondRule->keyword);
    }

    public function test_parse_preview_allows_any_inbound_rows_without_uniqueness_conflict(): void
    {
        $channel = Channel::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
            'keyword' => null,
            'normalized_keyword' => null,
            'reply_text' => 'Existing any inbound reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое any_inbound правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                '',
                '',
                'Импортируемый any_inbound ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(1, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
    }

    public function test_parse_preview_allows_same_keyword_for_different_match_scopes(): void
    {
        $channel = Channel::factory()->create();

        AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'ONASA01',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('ONASA01'),
            'reply_text' => 'Existing exact keyword reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое contains_text правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT,
                'ONASA01',
                '',
                'Импортируемый contains_text ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(1, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
    }

    public function test_import_allows_update_create_handoff_in_row_order(): void
    {
        $channel = Channel::factory()->create();
        $existingRule = AutoReplyRule::factory()->create([
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'KEY_A',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('KEY_A'),
            'reply_text' => 'Existing reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                (string) $existingRule->id,
                'Обновляем существующее правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'KEY_B',
                '',
                'Обновлённый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
            [
                '',
                'Создаём новое правило на освободившийся ключ',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'KEY_A',
                '',
                'Новый ответ',
                'none',
                '',
                '',
                (string) $channel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(1, $preview->createCount());
        $this->assertSame(1, $preview->updateCount());

        app(ApplyAutoReplyRulesWorkbookImportAction::class)->handle($preview);

        $existingRule->refresh();

        $this->assertSame('KEY_B', $existingRule->keyword);
        $this->assertDatabaseHas('auto_reply_rules', [
            'channel_id' => $channel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('KEY_A'),
            'reply_text' => 'Новый ответ',
        ]);
    }

    public function test_parse_preview_detects_request_phone_conflict_using_telegram_primary_channel(): void
    {
        $telegramChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);

        AutoReplyRule::factory()->create([
            'channel_id' => $telegramChannel->id,
            'match_scope' => AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
            'keyword' => 'PHONE_KEY',
            'normalized_keyword' => AutoReplyRule::normalizeKeyword('PHONE_KEY'),
            'reply_text' => 'Existing telegram reply',
            'is_active' => true,
            'priority' => 10,
        ]);

        $path = $this->storeWorkbook([
            AutoReplyRuleWorkbookFormat::rulesColumns(),
            [
                '',
                'Новое request_phone правило',
                '',
                '1',
                '10',
                AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD,
                'PHONE_KEY',
                '',
                'Импортируемый ответ',
                AutoReplyRuleWorkbookFormat::BUTTON_KIND_REQUEST_PHONE,
                '',
                '',
                $maxChannel->id.';'.$telegramChannel->id,
                '',
                '',
                '',
                '',
            ],
        ]);

        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertSame(0, $preview->createCount());
        $this->assertSame(0, $preview->updateCount());
        $this->assertSame(1, $preview->errorCount());
        $this->assertSame('id', $preview->errors[0]->column);
        $this->assertStringContainsString('Укажите id для обновления', $preview->errors[0]->message);
    }

    public function test_import_round_trip_preserves_normalized_rule_state(): void
    {
        $category = AutoReplyCategory::factory()->create(['name' => 'Fallback']);
        $telegramChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $requiredTag = Tag::factory()->create(['name' => 'VIP']);
        $assignTag = Tag::factory()->create(['name' => 'Лид']);

        $rule = AutoReplyRule::factory()
            ->forChannels([$telegramChannel, $maxChannel])
            ->create([
                'name' => 'Fallback rule',
                'auto_reply_category_id' => $category->id,
                'channel_id' => $maxChannel->id,
                'match_scope' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                'keyword' => null,
                'normalized_keyword' => null,
                'contact_phone_condition' => AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE,
                'reply_text' => 'Напишите оператору',
                'telegram_button_type' => null,
                'max_button_type' => AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE,
                'priority' => 20,
            ]);

        $rule->channels()->sync([
            $telegramChannel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT,
                'button_text' => null,
                'button_url' => null,
            ],
            $maxChannel->id => [
                'button_type' => AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT,
                'button_text' => null,
                'button_url' => null,
            ],
        ]);

        app(SyncAutoReplyRuleTagConditionsAction::class)->handle($rule, [$requiredTag->id], []);
        app(SyncAutoReplyRuleTagEffectsAction::class)->handle($rule, [$assignTag->id], []);

        $before = $this->snapshotRule($rule->fresh(['category', 'channels', 'tagConditions', 'tagEffects']));

        $spreadsheet = app(ExportAutoReplyRulesWorkbookAction::class)->handle();
        $path = $this->storeWorkbookFromSpreadsheet($spreadsheet);
        $preview = app(ParseAutoReplyRulesWorkbookAction::class)->handle($path);

        $this->assertFalse($preview->hasErrors());
        $this->assertSame(0, $preview->createCount());
        $this->assertSame(1, $preview->updateCount());

        app(ApplyAutoReplyRulesWorkbookImportAction::class)->handle($preview);

        $after = $this->snapshotRule($rule->fresh(['category', 'channels', 'tagConditions', 'tagEffects']));

        $this->assertSame($before, $after);
    }

    /**
     * @param  list<array<int, mixed>>  $rows
     */
    protected function storeWorkbook(array $rows): string
    {
        $spreadsheet = new Spreadsheet();

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(AutoReplyRuleWorkbookFormat::SHEET_RULES);

            foreach ($rows as $index => $row) {
                $sheet->fromArray([$row], null, 'A'.($index + 1));
            }

            $path = tempnam(sys_get_temp_dir(), 'auto-reply-rules-xlsx');

            if ($path === false) {
                $this->fail('Failed to allocate temporary workbook path.');
            }

            $finalPath = $path.'.xlsx';

            if (file_exists($path)) {
                unlink($path);
            }

            (new Xlsx($spreadsheet))->save($finalPath);

            return $finalPath;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    protected function storeWorkbookFromSpreadsheet(Spreadsheet $spreadsheet): string
    {
        try {
            $path = tempnam(sys_get_temp_dir(), 'auto-reply-rules-xlsx');

            if ($path === false) {
                $this->fail('Failed to allocate temporary workbook path.');
            }

            $finalPath = $path.'.xlsx';

            if (file_exists($path)) {
                unlink($path);
            }

            (new Xlsx($spreadsheet))->save($finalPath);

            return $finalPath;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshotRule(AutoReplyRule $rule): array
    {
        return [
            'name' => $rule->name,
            'category_id' => $rule->auto_reply_category_id,
            'channel_id' => $rule->channel_id,
            'is_active' => (bool) $rule->is_active,
            'priority' => (int) $rule->priority,
            'match_scope' => $rule->match_scope,
            'keyword' => $rule->keyword,
            'contact_phone_condition' => $rule->contact_phone_condition,
            'reply_text' => $rule->reply_text,
            'telegram_button_type' => $rule->telegram_button_type,
            'max_button_type' => $rule->max_button_type,
            'channels' => $rule->channels
                ->sortBy('id')
                ->map(fn (Channel $channel): array => [
                    'id' => (int) $channel->id,
                    'button_type' => $channel->pivot?->button_type,
                    'button_text' => $channel->pivot?->button_text,
                    'button_url' => $channel->pivot?->button_url,
                ])
                ->values()
                ->all(),
            'required_tags' => $rule->tagConditions
                ->where('condition', AutoReplyRuleTagCondition::CONDITION_REQUIRED)
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->sort()
                ->values()
                ->all(),
            'excluded_tags' => $rule->tagConditions
                ->where('condition', AutoReplyRuleTagCondition::CONDITION_EXCLUDED)
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->sort()
                ->values()
                ->all(),
            'assign_tags' => $rule->tagEffects
                ->where('effect', AutoReplyRuleTagEffect::EFFECT_ASSIGN)
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->sort()
                ->values()
                ->all(),
            'remove_tags' => $rule->tagEffects
                ->where('effect', AutoReplyRuleTagEffect::EFFECT_REMOVE)
                ->pluck('tag_id')
                ->map(fn (mixed $tagId): int => (int) $tagId)
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
