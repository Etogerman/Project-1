<?php

namespace App\Services\Scenarios;

use InvalidArgumentException;

/**
 * Machine-readable registry of the system fields supported by the scenario
 * engine. The single place in the engine that knows which fields exist, which
 * can be read in edge conditions, which can be written by the change-field
 * action, and which engine-internal contexts work with which field subsets.
 *
 * The registry intentionally stores no human-facing names or descriptions:
 * the meaning of a field belongs to the field dictionary. Field types are
 * carried only for cross-checking the registry against the dictionary.
 */
class EngineFieldRegistry
{
    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_DIALOG = 'dialog';

    /**
     * Contact fields readable in edge conditions. `writable` marks the fields
     * available to the change-field action. Types use the field dictionary
     * vocabulary: text, number, select, boolean, date, phone, email.
     *
     * @var array<string, array{readable: bool, writable: bool, type: string}>
     */
    private const CONTACT_FIELDS = [
        'id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'phone' => ['readable' => true, 'writable' => false, 'type' => 'phone'],
        'phones' => ['readable' => true, 'writable' => false, 'type' => 'phone'],
        'first_name' => ['readable' => true, 'writable' => true, 'type' => 'text'],
        'first_name_source' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'first_name_resolution_method' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'last_name' => ['readable' => true, 'writable' => true, 'type' => 'text'],
        'country' => ['readable' => true, 'writable' => true, 'type' => 'text'],
        'city' => ['readable' => true, 'writable' => true, 'type' => 'text'],
        'region' => ['readable' => true, 'writable' => true, 'type' => 'text'],
        'gender' => ['readable' => true, 'writable' => true, 'type' => 'select'],
        'gender_source' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'birth_date' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'age_years' => ['readable' => true, 'writable' => true, 'type' => 'number'],
        'age_range' => ['readable' => true, 'writable' => true, 'type' => 'select'],
        'region_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'region_source' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'distance_to_moscow_km' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'distance_to_moscow_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'distance_to_moscow_calculated_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'data_collection_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'data_collection_current_field' => ['readable' => true, 'writable' => false, 'type' => 'text'],
        'data_collection_last_prompted_field' => ['readable' => true, 'writable' => false, 'type' => 'text'],
        'data_collection_started_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'data_collection_current_field_started_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'data_collection_completed_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'data_collection_attempts_count' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'is_auto_reply_enabled' => ['readable' => true, 'writable' => false, 'type' => 'boolean'],
        'assigned_user_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'bitrix24_contact_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'bitrix24_sync_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'bitrix24_last_synced_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'bitrix24_deal_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'bitrix24_deal_sync_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'bitrix24_deal_last_synced_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'bitrix24_history_sync_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'bitrix24_history_last_synced_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'created_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'updated_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
    ];

    /**
     * Dialog system fields readable in edge conditions. User-defined dialog
     * fields are supported universally through the dialog fields payload and
     * are intentionally not listed here.
     *
     * @var array<string, array{readable: bool, writable: bool, type: string}>
     */
    private const DIALOG_FIELDS = [
        'id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'contact_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'channel_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'stage' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'phone' => ['readable' => true, 'writable' => false, 'type' => 'phone'],
        'bot_subscription_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'bot_subscription_changed_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'external_chat_id' => ['readable' => true, 'writable' => false, 'type' => 'text'],
        'bitrix24_live_chat_id' => ['readable' => true, 'writable' => false, 'type' => 'text'],
        'bitrix24_live_status' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'bitrix24_live_last_exported_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'bitrix24_live_last_imported_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'phone_confirmed_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'phone_confirmed_via' => ['readable' => true, 'writable' => false, 'type' => 'select'],
        'last_message_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'last_inbound_message_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'last_outbound_message_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'last_message_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'last_inbound_message_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'last_outbound_message_id' => ['readable' => true, 'writable' => false, 'type' => 'number'],
        'created_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
        'updated_at' => ['readable' => true, 'writable' => false, 'type' => 'date'],
    ];

    /**
     * Compatibility aliases accepted wherever the engine reads contact fields.
     * An alias is not a field: it resolves to the target field before any
     * value lookup.
     *
     * @var array<string, array<string, string>>
     */
    private const READ_ALIASES = [
        self::ENTITY_CONTACT => [
            'location_source' => 'region_source',
        ],
        self::ENTITY_DIALOG => [],
    ];

    /**
     * Contact fields the data-collection capture flow may write.
     *
     * @var list<string>
     */
    public const CONTACT_CAPTURE_FIELDS = [
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

    /**
     * Capture data types keyed by capture field.
     *
     * @var array<string, string>
     */
    public const CONTACT_CAPTURE_DATA_TYPES = [
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

    /**
     * Contact fields available to the change-field action.
     *
     * @var list<string>
     */
    public const CONTACT_CHANGE_FIELD_FIELDS = [
        'first_name',
        'last_name',
        'country',
        'region',
        'city',
        'gender',
        'age_years',
        'age_range',
    ];

    /**
     * Contact fields transition effects may write.
     *
     * @var list<string>
     */
    public const CONTACT_TRANSITION_WRITE_FIELDS = [
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

    /**
     * Contact fields readable by the field-condition block.
     *
     * @var list<string>
     */
    public const CONTACT_FIELD_CONDITION_FIELDS = [
        'phone',
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

    /**
     * Contact fields readable by message-text and AI-prompt variables.
     *
     * @var list<string>
     */
    public const CONTACT_PROMPT_VARIABLE_FIELDS = [
        'phone',
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

    /**
     * @return array<string, array{readable: bool, writable: bool, type: string}>
     */
    public static function fields(string $entity): array
    {
        return match ($entity) {
            self::ENTITY_CONTACT => self::CONTACT_FIELDS,
            self::ENTITY_DIALOG => self::DIALOG_FIELDS,
            default => throw new InvalidArgumentException(sprintf('Unknown engine field entity [%s].', $entity)),
        };
    }

    /**
     * Field keys readable in edge conditions, compatibility aliases included.
     *
     * @return list<string>
     */
    public static function readableFieldKeys(string $entity): array
    {
        $keys = array_keys(array_filter(
            self::fields($entity),
            fn (array $field): bool => $field['readable'],
        ));

        foreach (self::readAliases($entity) as $alias => $target) {
            if (in_array($target, $keys, true)) {
                $keys[] = $alias;
            }
        }

        return $keys;
    }

    /**
     * Field keys writable by the change-field action.
     *
     * @return list<string>
     */
    public static function writableFieldKeys(string $entity): array
    {
        return array_keys(array_filter(
            self::fields($entity),
            fn (array $field): bool => $field['writable'],
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function readAliases(string $entity): array
    {
        self::fields($entity);

        return self::READ_ALIASES[$entity];
    }

    public static function resolveReadAlias(string $entity, string $field): string
    {
        return self::readAliases($entity)[$field] ?? $field;
    }
}
