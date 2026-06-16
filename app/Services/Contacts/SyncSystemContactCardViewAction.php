<?php

namespace App\Services\Contacts;

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Models\CardViewTab;
use App\Models\FieldDictionaryField;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncSystemContactCardViewAction
{
    public const VIEW_KEY = 'system_contact_card';

    public const EDITABLE_VIEW_KEY = 'contact_card_default';

    public const TAB_GENERAL = 'general';

    public const TAB_DIALOGS = 'dialogs';

    public const TAB_BITRIX24 = 'bitrix24';

    public const TAB_DEDUP = 'dedup';

    public const TAB_SYSTEM_FIELDS = 'system_fields';

    public const TAB_HISTORY = 'history';

    public const TAB_DIAGNOSTICS = 'diagnostics';

    public const BLOCK_CONTACT_DIALOGS = 'contact_dialogs';

    public const BLOCK_CONTACT_PHONES = 'contact_phones';

    public const BLOCK_CONTACT_EMAILS = 'contact_emails';

    public const BLOCK_CONTACT_TAGS = 'contact_tags';

    public const BLOCK_CONTACT_DEDUP = 'contact_dedup';

    public const BLOCK_CONTACT_HISTORY = 'contact_history';

    public const BLOCK_CONTACT_DIAGNOSTICS = 'contact_diagnostics';

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const GENERAL_SECTIONS = [
        'client_data' => [
            'name' => 'Данные клиента',
            'sort_order' => 10,
            'fields' => [
                'first_name',
                'first_name_source',
                'last_name',
                'first_name_resolution_method',
                'gender',
                'gender_source',
                'birth_date',
                'effective_age_years',
                'age_range',
            ],
        ],
        'location' => [
            'name' => 'Локация',
            'sort_order' => 20,
            'fields' => [
                'country',
                'city',
                'region',
                'region_status',
                'region_source',
                'pending_region_candidates',
                'distance_to_moscow_km',
                'distance_to_moscow_status',
                'distance_to_moscow_calculated_at',
            ],
        ],
        'work' => [
            'name' => 'Работа с контактом',
            'sort_order' => 30,
            'fields' => [
                'assigned_user_id',
                'is_auto_reply_enabled',
                'has_blocked_bot_dialog',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const GENERAL_COMPLEX_FIELD_SECTIONS = [
        'contact_phones' => [
            'name' => 'Телефоны',
            'sort_order' => 40,
            'fields' => [
                'phones',
            ],
        ],
        'contact_emails' => [
            'name' => 'Email',
            'sort_order' => 50,
            'fields' => [
                'emails',
            ],
        ],
        'contact_tags' => [
            'name' => 'Теги',
            'sort_order' => 60,
            'fields' => [
                'tags',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    private const DIALOG_SECTIONS = [
        'contact_dialogs' => [
            'name' => 'Диалоги',
            'sort_order' => 10,
            'blocks' => [
                self::BLOCK_CONTACT_DIALOGS,
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    private const HISTORY_SECTIONS = [
        'contact_history' => [
            'name' => 'История',
            'sort_order' => 10,
            'blocks' => [
                self::BLOCK_CONTACT_HISTORY,
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    private const DEDUP_SECTIONS = [
        'contact_dedup' => [
            'name' => 'Склейки',
            'sort_order' => 10,
            'blocks' => [
                self::BLOCK_CONTACT_DEDUP,
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    private const DIAGNOSTICS_SECTIONS = [
        'contact_diagnostics' => [
            'name' => 'Диагностика',
            'sort_order' => 10,
            'blocks' => [
                self::BLOCK_CONTACT_DIAGNOSTICS,
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const BITRIX24_SECTIONS = [
        'bitrix24_contact' => [
            'name' => 'Контакт в Bitrix24',
            'sort_order' => 10,
            'fields' => [
                'bitrix24_contact_id',
                'bitrix24_sync_status',
                'bitrix24_last_synced_at',
                'bitrix24_linked_at',
                'bitrix24_sync_pending',
                'bitrix24_sync_fingerprint',
            ],
        ],
        'bitrix24_deal' => [
            'name' => 'Сделка в Bitrix24',
            'sort_order' => 20,
            'fields' => [
                'bitrix24_deal_id',
                'bitrix24_deal_sync_status',
                'bitrix24_deal_last_synced_at',
                'bitrix24_deal_linked_at',
                'bitrix24_deal_sync_pending',
            ],
        ],
        'bitrix24_history' => [
            'name' => 'История в Bitrix24',
            'sort_order' => 30,
            'fields' => [
                'bitrix24_history_sync_status',
                'bitrix24_history_last_synced_at',
                'bitrix24_history_sync_pending',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const SYSTEM_FIELD_SECTIONS = [
        'system_identity' => [
            'name' => 'Служебные поля контакта',
            'sort_order' => 10,
            'fields' => [
                'id',
                'created_at',
                'updated_at',
            ],
        ],
        'system_dedup' => [
            'name' => 'Склейки и дубли',
            'sort_order' => 20,
            'fields' => [
                'duplicate_review_status',
                'merged_into_contact_id',
                'merged_at',
                'merge_reason',
                'merge_trigger_phone',
            ],
        ],
    ];

    public function handle(): CardView
    {
        return DB::transaction(function (): CardView {
            $editableViewIsDefault = CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', self::EDITABLE_VIEW_KEY)
                ->where('is_default', true)
                ->exists();

            $defaultViewKey = $editableViewIsDefault ? self::EDITABLE_VIEW_KEY : self::VIEW_KEY;

            CardView::query()
                ->where('entity', CardView::ENTITY_CONTACT)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true)
                ->where('view_key', '!=', $defaultViewKey)
                ->update(['is_default' => false]);

            $view = CardView::query()->updateOrCreate(
                [
                    'entity' => CardView::ENTITY_CONTACT,
                    'context' => CardView::CONTEXT_CARD,
                    'view_key' => self::VIEW_KEY,
                ],
                [
                    'name' => 'Стандартная карточка контакта',
                    'is_system' => true,
                    'scope' => CardView::SCOPE_SYSTEM,
                    'is_default' => ! $editableViewIsDefault,
                ],
            );

            $this->syncFieldTab($view, self::TAB_GENERAL, 'Общее', 10, self::GENERAL_SECTIONS);
            $this->syncFieldTab($view, self::TAB_GENERAL, 'Общее', 10, self::GENERAL_COMPLEX_FIELD_SECTIONS);
            $this->syncBlockTab($view, self::TAB_DIALOGS, 'Диалоги', 20, self::DIALOG_SECTIONS);
            $this->syncFieldTab($view, self::TAB_BITRIX24, 'Битрикс24', 30, self::BITRIX24_SECTIONS);
            $this->syncBlockTab($view, self::TAB_HISTORY, 'История', 40, self::HISTORY_SECTIONS);
            $this->syncBlockTab($view, self::TAB_DEDUP, 'Склейки', 50, self::DEDUP_SECTIONS);
            $this->syncFieldTab($view, self::TAB_SYSTEM_FIELDS, 'Системные поля', 90, self::SYSTEM_FIELD_SECTIONS);
            $this->syncBlockTab($view, self::TAB_DIAGNOSTICS, 'Диагностика', 100, self::DIAGNOSTICS_SECTIONS);

            return $view->refresh();
        });
    }

    /**
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function generalSections(): array
    {
        return self::GENERAL_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function generalComplexFieldSections(): array
    {
        return self::GENERAL_COMPLEX_FIELD_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    public static function dialogSections(): array
    {
        return self::DIALOG_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    public static function historySections(): array
    {
        return self::HISTORY_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    public static function dedupSections(): array
    {
        return self::DEDUP_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,blocks:list<string>}>
     */
    public static function diagnosticsSections(): array
    {
        return self::DIAGNOSTICS_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function bitrix24Sections(): array
    {
        return self::BITRIX24_SECTIONS;
    }

    /**
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function systemFieldSections(): array
    {
        return self::SYSTEM_FIELD_SECTIONS;
    }

    /**
     * @param  array<string, array{name:string,sort_order:int,fields:list<string>}>  $sections
     */
    private function syncFieldTab(CardView $view, string $tabKey, string $name, int $sortOrder, array $sections): void
    {
        $tab = CardViewTab::query()->updateOrCreate(
            [
                'card_view_id' => $view->id,
                'tab_key' => $tabKey,
            ],
            [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_visible' => true,
                'is_system' => true,
            ],
        );

        foreach ($sections as $sectionKey => $sectionDefinition) {
            $section = CardViewSection::query()->updateOrCreate(
                [
                    'card_view_tab_id' => $tab->id,
                    'section_key' => $sectionKey,
                ],
                [
                    'name' => $sectionDefinition['name'],
                    'sort_order' => $sectionDefinition['sort_order'],
                    'is_visible' => true,
                    'is_collapsed_by_default' => false,
                    'is_system' => true,
                ],
            );

            CardViewItem::query()
                ->where('card_view_section_id', $section->id)
                ->where('is_system', true)
                ->whereNotIn('item_key', $sectionDefinition['fields'])
                ->delete();

            foreach ($sectionDefinition['fields'] as $index => $fieldKey) {
                $field = FieldDictionaryField::query()
                    ->where('entity', FieldDictionaryField::ENTITY_CONTACT)
                    ->where('field_key', $fieldKey)
                    ->first();

                if (! $field instanceof FieldDictionaryField) {
                    throw new RuntimeException("Поле контакта {$fieldKey} отсутствует в справочнике полей.");
                }

                CardViewItem::query()->updateOrCreate(
                    [
                        'card_view_section_id' => $section->id,
                        'item_key' => $fieldKey,
                    ],
                    [
                        'item_type' => CardViewItem::TYPE_FIELD,
                        'field_dictionary_field_id' => $field->id,
                        'sort_order' => ($index + 1) * 10,
                        'is_visible' => true,
                        'is_system' => true,
                    ],
                );
            }
        }
    }

    /**
     * @param  array<string, array{name:string,sort_order:int,blocks:list<string>}>  $sections
     */
    private function syncBlockTab(CardView $view, string $tabKey, string $name, int $sortOrder, array $sections): void
    {
        $tab = CardViewTab::query()->updateOrCreate(
            [
                'card_view_id' => $view->id,
                'tab_key' => $tabKey,
            ],
            [
                'name' => $name,
                'sort_order' => $sortOrder,
                'is_visible' => true,
                'is_system' => true,
            ],
        );

        foreach ($sections as $sectionKey => $sectionDefinition) {
            $section = CardViewSection::query()->updateOrCreate(
                [
                    'card_view_tab_id' => $tab->id,
                    'section_key' => $sectionKey,
                ],
                [
                    'name' => $sectionDefinition['name'],
                    'sort_order' => $sectionDefinition['sort_order'],
                    'is_visible' => true,
                    'is_collapsed_by_default' => false,
                    'is_system' => true,
                ],
            );

            CardViewItem::query()
                ->where('card_view_section_id', $section->id)
                ->where('is_system', true)
                ->whereNotIn('item_key', $sectionDefinition['blocks'])
                ->delete();

            foreach ($sectionDefinition['blocks'] as $index => $blockKey) {
                CardViewItem::query()->updateOrCreate(
                    [
                        'card_view_section_id' => $section->id,
                        'item_key' => $blockKey,
                    ],
                    [
                        'item_type' => CardViewItem::TYPE_BLOCK,
                        'field_dictionary_field_id' => null,
                        'sort_order' => ($index + 1) * 10,
                        'is_visible' => true,
                        'is_system' => true,
                    ],
                );
            }
        }
    }
}
