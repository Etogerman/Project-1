<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactEmail;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Services\Scenarios\EngineFieldRegistry;
use App\Services\Scenarios\FieldDictionaryEngineSupport;
use App\Services\Scenarios\GenericDbScenarioRuntime;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Snapshot harness for the scenario engine field lists.
 *
 * The expected arrays below are the etalon copies of the engine field lists
 * captured before the registry refactoring. They guard that moving the lists
 * into the registry did not add, drop or rename a single key, and that the
 * observable behavior around the lists stays intact.
 */
class EngineFieldRegistryTest extends TestCase
{
    use RefreshDatabase;

    private const EXPECTED_CONTACT_CONDITION_READ_FIELDS = [
        'id',
        'phone',
        'phones',
        'emails',
        'first_name',
        'first_name_source',
        'first_name_resolution_method',
        'last_name',
        'country',
        'city',
        'region',
        'gender',
        'gender_source',
        'birth_date',
        'age_years',
        'age_range',
        'region_status',
        'region_source',
        'location_source',
        'distance_to_moscow_km',
        'distance_to_moscow_status',
        'distance_to_moscow_calculated_at',
        'data_collection_status',
        'data_collection_current_field',
        'data_collection_last_prompted_field',
        'data_collection_started_at',
        'data_collection_current_field_started_at',
        'data_collection_completed_at',
        'data_collection_attempts_count',
        'is_auto_reply_enabled',
        'assigned_user_id',
        'duplicate_review_status',
        'merged_into_contact_id',
        'merged_at',
        'merge_reason',
        'merge_trigger_phone',
        'bitrix24_contact_id',
        'bitrix24_sync_status',
        'bitrix24_last_synced_at',
        'bitrix24_linked_at',
        'bitrix24_sync_pending',
        'bitrix24_sync_fingerprint',
        'bitrix24_deal_id',
        'bitrix24_deal_sync_status',
        'bitrix24_deal_last_synced_at',
        'bitrix24_deal_linked_at',
        'bitrix24_deal_sync_pending',
        'bitrix24_history_sync_status',
        'bitrix24_history_last_synced_at',
        'bitrix24_history_sync_pending',
        'created_at',
        'updated_at',
    ];

    private const EXPECTED_DIALOG_CONDITION_READ_FIELDS = [
        'id',
        'contact_id',
        'channel_id',
        'stage',
        'phone',
        'external_username',
        'bot_subscription_status',
        'bot_subscription_changed_at',
        'external_chat_id',
        'bitrix24_live_chat_id',
        'bitrix24_live_status',
        'bitrix24_live_last_exported_at',
        'bitrix24_live_last_imported_at',
        'phone_confirmed_at',
        'phone_confirmed_via',
        'last_message_at',
        'last_inbound_message_at',
        'last_outbound_message_at',
        'last_message_id',
        'last_inbound_message_id',
        'last_outbound_message_id',
        'created_at',
        'updated_at',
    ];

    private const EXPECTED_CONTACT_CAPTURE_FIELDS = [
        'phone',
        'first_name',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'age_years',
        'age_range',
        'region_status',
        'region_source',
        'location_source',
        'distance_to_moscow_km',
        'distance_to_moscow_status',
        'distance_to_moscow_calculated_at',
    ];

    private const EXPECTED_CONTACT_CAPTURE_DATA_TYPES = [
        'phone' => 'phone',
        'first_name' => 'any_text',
        'last_name' => 'any_text',
        'country' => 'any_text',
        'region' => 'any_text',
        'city' => 'any_text',
        'gender' => 'any_text',
        'age_years' => 'number',
        'age_range' => 'any_text',
    ];

    private const EXPECTED_CONTACT_CHANGE_FIELD_FIELDS = [
        'first_name',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'age_years',
        'age_range',
    ];

    private const EXPECTED_CONTACT_TRANSITION_WRITE_FIELDS = [
        'first_name',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'gender_source',
        'age_years',
        'age_range',
        'first_name_source',
        'first_name_resolution_method',
    ];

    private const EXPECTED_CONTACT_FIELD_CONDITION_FIELDS = [
        'phone',
        'emails',
        'first_name',
        'first_name_source',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'age_years',
        'age_range',
    ];

    private const EXPECTED_CONTACT_PROMPT_VARIABLE_FIELDS = [
        'phone',
        'emails',
        'first_name',
        'first_name_source',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'gender_source',
        'birth_date',
        'age_years',
        'age_range',
        'region_status',
        'region_source',
        'location_source',
        'distance_to_moscow_km',
        'distance_to_moscow_status',
        'distance_to_moscow_calculated_at',
    ];

    public function test_contact_condition_read_list_matches_etalon(): void
    {
        $this->assertEqualsCanonicalizing(
            self::EXPECTED_CONTACT_CONDITION_READ_FIELDS,
            EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT),
        );
    }

    public function test_dialog_condition_read_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_DIALOG_CONDITION_READ_FIELDS,
            EngineFieldRegistry::readableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG),
        );
    }

    public function test_contact_capture_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_CAPTURE_FIELDS,
            EngineFieldRegistry::CONTACT_CAPTURE_FIELDS,
        );
    }

    public function test_contact_capture_data_types_match_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_CAPTURE_DATA_TYPES,
            EngineFieldRegistry::CONTACT_CAPTURE_DATA_TYPES,
        );
    }

    public function test_contact_change_field_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_CHANGE_FIELD_FIELDS,
            EngineFieldRegistry::CONTACT_CHANGE_FIELD_FIELDS,
        );
    }

    public function test_contact_transition_write_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_TRANSITION_WRITE_FIELDS,
            EngineFieldRegistry::CONTACT_TRANSITION_WRITE_FIELDS,
        );
    }

    public function test_contact_field_condition_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_FIELD_CONDITION_FIELDS,
            EngineFieldRegistry::CONTACT_FIELD_CONDITION_FIELDS,
        );
    }

    public function test_contact_prompt_variable_list_matches_etalon(): void
    {
        $this->assertSame(
            self::EXPECTED_CONTACT_PROMPT_VARIABLE_FIELDS,
            EngineFieldRegistry::CONTACT_PROMPT_VARIABLE_FIELDS,
        );
    }

    public function test_registry_read_aliases_match_etalon(): void
    {
        $this->assertSame(
            ['location_source' => 'region_source'],
            EngineFieldRegistry::readAliases(EngineFieldRegistry::ENTITY_CONTACT),
        );
        $this->assertSame([], EngineFieldRegistry::readAliases(EngineFieldRegistry::ENTITY_DIALOG));
    }

    public function test_registry_writable_keys_match_change_field_list(): void
    {
        $this->assertEqualsCanonicalizing(
            self::EXPECTED_CONTACT_CHANGE_FIELD_FIELDS,
            EngineFieldRegistry::writableFieldKeys(EngineFieldRegistry::ENTITY_CONTACT),
        );
        $this->assertSame([], EngineFieldRegistry::writableFieldKeys(EngineFieldRegistry::ENTITY_DIALOG));
    }

    public function test_field_dictionary_support_matches_engine_registry(): void
    {
        FieldDictionaryField::syncSystemDefinitions();

        $this->assertSame([], app(FieldDictionaryEngineSupport::class)->consistencyProblems());
    }

    public function test_missing_dialog_field_is_not_supported_until_it_exists_in_dictionary(): void
    {
        $support = app(FieldDictionaryEngineSupport::class);

        $this->assertFalse($support->supportsDialogChangeField('новое_поле_диалога'));
        $this->assertFalse($support->supportsDialogFieldCondition('новое_поле_диалога'));

        FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'новое_поле_диалога',
            'name' => 'Новое поле диалога',
            'type' => FieldDictionaryField::TYPE_TEXT,
        ]);

        $this->assertTrue($support->supportsDialogChangeField('новое_поле_диалога'));
        $this->assertTrue($support->supportsDialogFieldCondition('новое_поле_диалога'));
    }

    public function test_registry_rejects_unknown_entity(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EngineFieldRegistry::fields('company');
    }

    public function test_condition_reads_contact_field(): void
    {
        $message = $this->createInboundMessage(contactOverrides: ['city' => 'Казань']);

        $this->assertTrue($this->evaluateCondition('{{contact.city}} == "Казань"', $message));
        $this->assertFalse($this->evaluateCondition('{{contact.city}} == "Москва"', $message));
    }

    public function test_condition_reads_contact_emails(): void
    {
        $message = $this->createInboundMessage();

        ContactEmail::factory()->create([
            'contact_id' => $message->contact_id,
            'email_raw' => 'CLIENT@Example.COM',
            'email_normalized' => 'client@example.com',
            'is_primary' => true,
        ]);

        $this->assertTrue($this->evaluateCondition('{{contact.emails}} != ""', $message));
        $this->assertTrue($this->evaluateCondition('{{contact.emails}} == "client@example.com"', $message));
        $this->assertFalse($this->evaluateCondition('{{contact.emails}} == "other@example.com"', $message));
    }

    public function test_condition_resolves_location_source_alias_to_region_source(): void
    {
        $message = $this->createInboundMessage(contactOverrides: ['region_source' => 'manual']);

        $this->assertTrue($this->evaluateCondition('{{contact.location_source}} == "manual"', $message));
        $this->assertTrue($this->evaluateCondition('{{contact.region_source}} == "manual"', $message));
    }

    public function test_condition_rejects_unknown_contact_field(): void
    {
        $message = $this->createInboundMessage();

        $this->expectException(InvalidArgumentException::class);

        $this->evaluateCondition('{{contact.unknown_field}} == "x"', $message);
    }

    public function test_condition_reads_dialog_system_field(): void
    {
        $message = $this->createInboundMessage();

        $this->assertTrue(
            $this->evaluateCondition('{{dialog.external_chat_id}} == "telegram-chat-700"', $message),
        );
        $this->assertTrue(
            $this->evaluateCondition('{{dialog.external_username}} == "telegram_user_500"', $message),
        );
    }

    public function test_condition_reads_dialog_user_field_from_fields_payload(): void
    {
        $message = $this->createInboundMessage(dialogOverrides: ['fields_payload' => ['vip_level' => 'gold']]);

        $this->assertTrue($this->evaluateCondition('{{dialog.vip_level}} == "gold"', $message));
        $this->assertFalse($this->evaluateCondition('{{dialog.vip_level}} == "silver"', $message));
    }

    public function test_runtime_field_condition_reads_dialog_system_and_user_fields(): void
    {
        $message = $this->createInboundMessage(dialogOverrides: [
            'stage' => Dialog::STAGE_PHONE_RECEIVED,
            'confirmed_phone_raw' => '+7 999 111 22 33',
            'confirmed_phone_normalized' => '+79991112233',
            'fields_payload' => ['vip_level' => 'gold'],
        ]);

        $this->assertSame(Dialog::STAGE_PHONE_RECEIVED, $this->runtimeFieldConditionValue($message, 'dialog', 'stage'));
        $this->assertSame('+7 999 111 22 33', $this->runtimeFieldConditionValue($message, 'dialog', 'phone'));
        $this->assertSame('telegram_user_500', $this->runtimeFieldConditionValue($message, 'dialog', 'external_username'));
        $this->assertSame('gold', $this->runtimeFieldConditionValue($message, 'dialog', 'vip_level'));
    }

    public function test_runtime_dialog_field_string_value_reads_system_field_for_simulated_start(): void
    {
        $message = $this->createInboundMessage(dialogOverrides: [
            'external_chat_id' => 'start-parameter-chat',
            'fields_payload' => ['start_param' => '/fallback'],
        ]);

        $this->assertSame('start-parameter-chat', $this->runtimeDialogFieldStringValue($message, 'external_chat_id'));
        $this->assertSame('/fallback', $this->runtimeDialogFieldStringValue($message, 'start_param'));
    }

    public function test_text_substitution_uses_listed_contact_field(): void
    {
        $message = $this->createInboundMessage(contactOverrides: ['city' => 'Казань']);

        $this->assertSame(
            'Город: Казань',
            $this->substituteText($message, 'Город: {{contact.city|нет города}}'),
        );
    }

    public function test_text_substitution_uses_contact_emails(): void
    {
        $message = $this->createInboundMessage();

        ContactEmail::factory()->create([
            'contact_id' => $message->contact_id,
            'email_raw' => 'Primary@Example.COM',
            'email_normalized' => 'primary@example.com',
            'is_primary' => true,
        ]);

        $this->assertSame(
            'Email: primary@example.com',
            $this->substituteText($message, 'Email: {{contact.emails|нет email}}'),
        );
    }

    public function test_text_substitution_uses_root_contact_email_for_merged_contact(): void
    {
        $message = $this->createInboundMessage();
        $root = Contact::factory()->create();

        $message->contact->forceFill([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ])->save();

        ContactEmail::factory()->create([
            'contact_id' => $root->id,
            'email_raw' => 'Root@Example.COM',
            'email_normalized' => 'root@example.com',
            'is_primary' => true,
        ]);

        $this->assertSame(
            'Email: root@example.com',
            $this->substituteText($message, 'Email: {{contact.emails|нет email}}'),
        );
    }

    public function test_text_substitution_resolves_location_source_alias(): void
    {
        $message = $this->createInboundMessage(contactOverrides: ['region_source' => 'manual']);

        $this->assertSame(
            'Источник: manual',
            $this->substituteText($message, 'Источник: {{contact.location_source|нет}}'),
        );
    }

    public function test_text_substitution_falls_back_for_field_outside_prompt_list(): void
    {
        $message = $this->createInboundMessage();

        // created_at is readable in edge conditions but is intentionally not
        // part of the prompt/text variable list, so the fallback must win.
        $this->assertSame(
            'Создан: неизвестно',
            $this->substituteText($message, 'Создан: {{contact.created_at|неизвестно}}'),
        );
        $this->assertSame(
            'Анкета: неизвестно',
            $this->substituteText($message, 'Анкета: {{contact.data_collection_status|неизвестно}}'),
        );
    }

    public function test_change_field_action_writes_allowed_contact_field(): void
    {
        $message = $this->createInboundMessage(contactOverrides: ['city' => null]);

        $this->assertTrue($this->applyChangeContactField($message, 'city', 'Казань'));
        $this->assertSame('Казань', $message->contact->refresh()->city);
    }

    public function test_change_field_action_rejects_field_outside_change_list(): void
    {
        $message = $this->createInboundMessage();
        $originalUpdatedAt = $message->contact->updated_at;

        $this->assertFalse($this->applyChangeContactField($message, 'bitrix24_contact_id', '123'));
        $this->assertFalse($this->applyChangeContactField($message, 'region_source', 'manual'));
        $this->assertEquals($originalUpdatedAt, $message->contact->refresh()->updated_at);
    }

    private function evaluateCondition(string $expression, Message $message): bool
    {
        return app(ScenarioEdgeExpressionCondition::class)->evaluate($expression, $message);
    }

    private function substituteText(Message $message, string $text): string
    {
        $method = new ReflectionMethod(GenericDbScenarioRuntime::class, 'v3TextWithVariables');

        return (string) $method->invoke(app(GenericDbScenarioRuntime::class), $message, $text, [], 'snapshot-block');
    }

    private function runtimeFieldConditionValue(Message $message, string $fieldScope, string $fieldKey): mixed
    {
        $method = new ReflectionMethod(GenericDbScenarioRuntime::class, 'v3FieldConditionValue');

        return $method->invoke(app(GenericDbScenarioRuntime::class), $message, $fieldScope, $fieldKey);
    }

    private function runtimeDialogFieldStringValue(Message $message, string $fieldKey): string
    {
        $method = new ReflectionMethod(GenericDbScenarioRuntime::class, 'v3DialogFieldStringValue');

        return (string) $method->invoke(app(GenericDbScenarioRuntime::class), $message, $fieldKey);
    }

    private function applyChangeContactField(Message $message, string $fieldKey, string $value): bool
    {
        $method = new ReflectionMethod(GenericDbScenarioRuntime::class, 'applyV3ChangeContactFieldAction');

        return (bool) $method->invoke(app(GenericDbScenarioRuntime::class), $message, $fieldKey, $value, [], []);
    }

    private function createInboundMessage(
        array $contactOverrides = [],
        array $dialogOverrides = [],
    ): Message {
        $channel = Channel::factory()->create();

        $contact = Contact::factory()->create(array_merge([
            'is_auto_reply_enabled' => true,
        ], $contactOverrides));

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-500',
            'external_username' => 'telegram_user_500',
        ]);

        $dialog = Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-700',
        ], $dialogOverrides));

        return Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'snapshot probe',
        ]);
    }
}
