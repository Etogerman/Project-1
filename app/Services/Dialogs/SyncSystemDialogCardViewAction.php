<?php

namespace App\Services\Dialogs;

use App\Models\CardView;
use App\Models\CardViewItem;
use App\Models\CardViewSection;
use App\Models\CardViewTab;
use App\Models\FieldDictionaryField;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SyncSystemDialogCardViewAction
{
    public const VIEW_KEY = 'system_dialog_card';

    public const EDITABLE_VIEW_KEY = 'dialog_card_default';

    public const TAB_GENERAL = 'general';

    public const TAB_BITRIX24 = 'bitrix24';

    public const TAB_SYSTEM_FIELDS = 'system_fields';

    public const TAB_DIAGNOSTICS = 'diagnostics';

    public const BLOCK_DIALOG_CONVERSATION = 'dialog_conversation';

    public const BLOCK_DIALOG_SYSTEM_FIELDS = 'dialog_system_fields';

    public const BLOCK_DIALOG_FIELDS = 'dialog_fields';

    public const BLOCK_DIALOG_PEER_SYNC = 'dialog_peer_sync';

    public const SECTION_DIALOG_CONVERSATION = 'dialog_conversation';

    public const SECTION_DIALOG_SIDE_DATA = 'dialog_side_data';

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const GENERAL_FIELD_SECTIONS = [
        self::SECTION_DIALOG_SIDE_DATA => [
            'name' => 'Данные диалога',
            'sort_order' => 10,
            'fields' => [
                'id',
                'contact_id',
                'channel_id',
                'status',
                'assigned_user_id',
                'current_block_id',
                'created_at',
                'updated_at',
                'last_message_at',
                'last_inbound_message_at',
                'last_outbound_message_at',
                'phone',
                'external_username',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const BITRIX24_SECTIONS = [
        'bitrix24_open_lines' => [
            'name' => 'Открытые линии Bitrix24',
            'sort_order' => 10,
            'fields' => [
                'bitrix24_live_chat_id',
                'bitrix24_live_status',
                'bitrix24_live_last_exported_at',
                'bitrix24_live_last_imported_at',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const SYSTEM_FIELD_SECTIONS = [
        'dialog_identity' => [
            'name' => 'Дополнительные данные диалога',
            'sort_order' => 10,
            'fields' => [
                'stage',
                'avatar',
                'external_chat_id',
            ],
        ],
        'dialog_subscription' => [
            'name' => 'Подписка и телефон',
            'sort_order' => 20,
            'fields' => [
                'bot_subscription_status',
                'bot_subscription_changed_at',
                'phone_confirmed_at',
                'phone_confirmed_via',
            ],
        ],
        'dialog_activity' => [
            'name' => 'Активность',
            'sort_order' => 30,
            'fields' => [
                'last_message_id',
                'last_inbound_message_id',
                'last_outbound_message_id',
            ],
        ],
    ];

    /**
     * @var array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    private const DIAGNOSTICS_SECTIONS = [
        'dialog_peer_sync' => [
            'name' => 'Загрузка истории',
            'sort_order' => 10,
            'fields' => [
                'peer_sync',
            ],
        ],
    ];

    public function handle(): CardView
    {
        return DB::transaction(function (): CardView {
            $editableViewIsDefault = CardView::query()
                ->where('entity', CardView::ENTITY_DIALOG)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('view_key', self::EDITABLE_VIEW_KEY)
                ->where('is_default', true)
                ->exists();

            $defaultViewKey = $editableViewIsDefault ? self::EDITABLE_VIEW_KEY : self::VIEW_KEY;

            CardView::query()
                ->where('entity', CardView::ENTITY_DIALOG)
                ->where('context', CardView::CONTEXT_CARD)
                ->where('is_default', true)
                ->where('view_key', '!=', $defaultViewKey)
                ->update(['is_default' => false]);

            $view = CardView::query()->updateOrCreate(
                [
                    'entity' => CardView::ENTITY_DIALOG,
                    'context' => CardView::CONTEXT_CARD,
                    'view_key' => self::VIEW_KEY,
                ],
                [
                    'name' => 'Стандартная карточка диалога',
                    'is_system' => true,
                    'scope' => CardView::SCOPE_SYSTEM,
                    'is_default' => ! $editableViewIsDefault,
                ],
            );

            $this->syncFieldTab($view, self::TAB_GENERAL, 'Общее', 10, self::GENERAL_FIELD_SECTIONS);
            $this->syncFieldTab($view, self::TAB_BITRIX24, 'Битрикс24', 20, self::BITRIX24_SECTIONS);
            $this->syncFieldTab($view, self::TAB_SYSTEM_FIELDS, 'Системные поля', 90, self::SYSTEM_FIELD_SECTIONS);
            $this->syncFieldTab($view, self::TAB_DIAGNOSTICS, 'Диагностика', 100, self::DIAGNOSTICS_SECTIONS);

            return $view->refresh();
        });
    }

    /**
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function generalFieldSections(): array
    {
        return self::GENERAL_FIELD_SECTIONS;
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
     * @return array<string, array{name:string,sort_order:int,fields:list<string>}>
     */
    public static function diagnosticsSections(): array
    {
        return self::DIAGNOSTICS_SECTIONS;
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

        $this->deleteStaleSystemSections($tab, array_keys($sections));

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
                    ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
                    ->where('field_key', $fieldKey)
                    ->first();

                if (! $field instanceof FieldDictionaryField) {
                    throw new RuntimeException("Поле диалога {$fieldKey} отсутствует в справочнике полей.");
                }

                CardViewItem::query()
                    ->where('item_key', $fieldKey)
                    ->where('card_view_section_id', '!=', $section->id)
                    ->where('is_system', true)
                    ->whereHas('section.tab', fn ($query) => $query->where('card_view_id', $view->id))
                    ->delete();

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

        $this->deleteStaleSystemSections($tab, array_keys($sections));

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

    /**
     * @param  list<string>  $sectionKeys
     */
    private function deleteStaleSystemSections(CardViewTab $tab, array $sectionKeys): void
    {
        CardViewSection::query()
            ->where('card_view_tab_id', $tab->id)
            ->where('is_system', true)
            ->whereNotIn('section_key', $sectionKeys)
            ->get()
            ->each(function (CardViewSection $section): void {
                $section->items()->delete();
                $section->delete();
            });
    }
}
