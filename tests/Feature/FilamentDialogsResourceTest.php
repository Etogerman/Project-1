<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Filament\Resources\Dialogs\Pages\ListDialogs;
use App\Filament\Resources\Dialogs\Pages\ViewDialog;
use App\Models\Channel;
use App\Models\ChannelPeerSyncState;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Scenario;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Bots\ContactIdentityAvatarStorage;
use App\Services\Dialogs\BuildDialogMessageSnapshotPayloadAction;
use App\Services\Dialogs\LoadDialogMessagesPageAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentDialogsResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_active_admin_can_open_dialog_view_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Диалог')
            ->assertDontSee('<h1 class="fi-header-heading', false)
            ->assertSee('Открыть контакт')
            ->assertSee('Сообщения диалога')
            ->assertSee('Написать клиенту')
            ->assertSee('aria-label="Текст ответа"', false)
            ->assertDontSee('Технический контекст')
            ->assertDontSee('Маршрут и идентификаторы')
            ->assertDontSee('Этот блок нужен для диагностики маршрута')
            ->assertDontSee('История текущего канала')
            ->assertDontSee('Сообщение уйдёт в этот диалог')
            ->assertDontSee('Ответ будет отправлен от имени выбранного канала')
            ->assertDontSee('<label for="conversation-reply-textarea" class="ac-field-label">', false);
    }

    public function test_dialog_view_renders_user_dialog_fields_without_service_payload(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->forceFill([
            'fields_payload' => [
                'client_city' => 'Москва',
                'test1' => '1',
                '_v3' => [
                    'transition_counts' => [
                        'published_1:edge_test' => 1,
                    ],
                ],
            ],
        ])->save();
        FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'client_city',
            'name' => 'Город клиента',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'options' => [],
            'source_field_key' => null,
            'sort_order' => 1000,
            'is_multiple' => false,
            'is_system' => false,
            'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
            'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
            'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Поля диалога')
            ->assertSee('data-role="dialog-fields-section"', false)
            ->assertSee('data-role="dialog-side-field-row"', false)
            ->assertSee('data-field-key="client_city"', false)
            ->assertDontSee('data-role="dialog-field-copy-key"', false)
            ->assertDontSee('Копировать ключ')
            ->assertSee('Город клиента')
            ->assertSee('client_city')
            ->assertSee('Москва')
            ->assertSee('data-role="dialog-field-editor"', false)
            ->assertDontSee('data-role="dialog-field-save-button"', false)
            ->assertDontSee('data-role="dialog-field-savebar"', false)
            ->assertSee('test1')
            ->assertSee('1')
            ->assertDontSee('data-field-key="_v3"', false)
            ->assertDontSee('_v3')
            ->assertDontSee('transition_counts');
    }

    public function test_dialog_view_allows_editing_user_dialog_field_values(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->forceFill([
            'fields_payload' => [
                'сколько_раз_спросили_имя' => 3,
                'client_city' => 'Москва',
                'readonly_note' => 'не менять',
                '_v3' => [
                    'transition_counts' => [
                        'published_1:edge_test' => 1,
                    ],
                ],
            ],
        ])->save();
        FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'сколько_раз_спросили_имя',
            'name' => 'Сколько раз спросили имя',
            'type' => FieldDictionaryField::TYPE_NUMBER,
            'options' => [],
            'source_field_key' => null,
            'sort_order' => 1000,
            'is_multiple' => false,
            'is_system' => false,
            'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
            'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
            'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
        ]);
        FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'client_city',
            'name' => 'Город клиента',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'options' => [],
            'source_field_key' => null,
            'sort_order' => 1001,
            'is_multiple' => false,
            'is_system' => false,
            'write_access' => FieldDictionaryField::WRITE_ACCESS_WRITABLE,
            'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE,
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_ALLOWED,
            'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
        ]);
        FieldDictionaryField::query()->create([
            'entity' => FieldDictionaryField::ENTITY_DIALOG,
            'field_key' => 'readonly_note',
            'name' => 'Заметка только для чтения',
            'type' => FieldDictionaryField::TYPE_TEXT,
            'options' => [],
            'source_field_key' => null,
            'sort_order' => 1002,
            'is_multiple' => false,
            'is_system' => false,
            'write_access' => FieldDictionaryField::WRITE_ACCESS_READ_ONLY,
            'manual_write_access' => FieldDictionaryField::MANUAL_WRITE_ACCESS_READONLY,
            'scenario_write_access' => FieldDictionaryField::SCENARIO_WRITE_ACCESS_DENIED,
            'value_owner' => FieldDictionaryField::VALUE_OWNER_OPERATOR,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogFieldDraftDirty', false)
            ->set('dialogFieldDraftValues.сколько_раз_спросили_имя', '7')
            ->set('dialogFieldDraftValues.client_city', 'Химки')
            ->assertSet('dialogFieldDraftDirty', true)
            ->assertSee('data-role="dialog-field-savebar"', false)
            ->call('saveDialogFieldDraftValues')
            ->assertSet('dialogFieldDraftDirty', false)
            ->call('saveDialogFieldValue', 'readonly_note', 'изменено');

        $dialog->refresh();

        $this->assertSame(7, data_get($dialog->fields_payload, 'сколько_раз_спросили_имя'));
        $this->assertSame('Химки', data_get($dialog->fields_payload, 'client_city'));
        $this->assertSame('не менять', data_get($dialog->fields_payload, 'readonly_note'));
        $this->assertSame(1, data_get($dialog->fields_payload, '_v3.transition_counts.published_1:edge_test'));
    }

    public function test_dialog_view_renders_empty_dialog_fields_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->forceFill([
            'fields_payload' => [
                '_v3' => [
                    'transition_counts' => [
                        'published_1:edge_test' => 1,
                    ],
                ],
            ],
        ])->save();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Поля диалога')
            ->assertSee('data-role="dialog-fields-empty"', false)
            ->assertSee('Поля диалога пока не заполнены')
            ->assertDontSee('data-field-key="_v3"', false)
            ->assertDontSee('_v3')
            ->assertDontSee('transition_counts');
    }

    public function test_dialog_view_renders_system_fields_and_current_v3_block(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->forceFill([
            'last_message_at' => now()->subMinutes(5),
            'last_message_preview' => 'Последний текст диалога',
            'last_inbound_at' => now()->subMinutes(7),
            'last_inbound_message_preview' => 'Входящий текст клиента',
            'last_outbound_at' => now()->subMinutes(6),
            'last_outbound_message_preview' => 'Ответ оператора',
        ])->save();

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'phone')
            ->firstOrFail()
            ->update(['name' => 'Телефон канала']);

        $publishedVersion = $this->createPublishedScenarioVersion([
            'builder_v3_runtime' => [
                'blocks' => [
                    '798' => [
                        'id' => '798',
                        'card_id' => '798',
                        'display_number' => '12',
                        'title' => 'Старт: анкета',
                    ],
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => $publishedVersion->scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => '798',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => '798',
                    'published_version_id' => $publishedVersion->id,
                ],
            ],
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-general-tab"', false)
            ->assertSee('data-role="dialog-stage-strip"', false)
            ->assertDontSee('data-field-key="stage"', false)
            ->assertSee('data-field-key="current_block_id"', false)
            ->assertSee('Текущий блок')
            ->assertDontSee('Текущий блок клиента')
            ->assertSee('#12 · Старт: анкета')
            ->assertSee('v'.$publishedVersion->id)
            ->assertSee('Телефон канала')
            ->assertSee('Последнее сообщение')
            ->assertSee('Последнее входящее')
            ->assertSee('Последнее исходящее')
            ->assertSee('data-field-key="contact_id"', false)
            ->assertSee('data-field-key="channel_id"', false)
            ->assertDontSee('Аватарка');
    }

    public function test_dialog_view_topbar_dialogs_breadcrumb_links_to_back_to_dialogs_list(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $backTo = DialogResource::getUrl('index');

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', [
                'record' => $dialog,
                'back_to' => $backTo,
            ]))
            ->assertOk()
            ->assertSee('<a class="ac-admin-breadcrumbs__item" href="'.$backTo.'">Диалоги</a>', false);
    }

    public function test_dialog_view_renders_empty_current_block_without_active_run(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-field-key="current_block_id"', false)
            ->assertSee('Текущий блок')
            ->assertSee('Сценарий не запускался')
            ->assertDontSee('Текущий блок клиента');
    }

    public function test_dialog_view_renders_last_known_v3_block_after_completed_run(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $publishedVersion = $this->createPublishedScenarioVersion([
            'builder_v3_runtime' => [
                'blocks' => [
                    '1066' => [
                        'id' => '1066',
                        'card_id' => '1066',
                        'title' => 'Возраст заполнен',
                    ],
                ],
            ],
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => $publishedVersion->scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'status' => 'completed',
                    'current_block_id' => null,
                    'last_known_block_id' => '1066',
                    'published_version_id' => $publishedVersion->id,
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-field-key="current_block_id"', false)
            ->assertSee('#1066 · Возраст заполнен')
            ->assertSee(ScenarioRun::STATUS_COMPLETED);
    }

    public function test_dialog_view_falls_back_to_last_v3_message_block_after_completed_run(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $publishedVersion = $this->createPublishedScenarioVersion([
            'builder_v3_runtime' => [
                'blocks' => [
                    '1063' => [
                        'id' => '1063',
                        'card_id' => '1063',
                        'title' => 'Пол заполнен',
                    ],
                ],
            ],
        ]);

        $run = ScenarioRun::query()->create([
            'scenario_code' => $publishedVersion->scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_COMPLETED,
            'current_step' => null,
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'status' => 'completed',
                    'current_block_id' => null,
                    'published_version_id' => $publishedVersion->id,
                ],
            ],
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'text' => 'Пол заполнен',
            'raw_payload' => [
                'v3' => [
                    'scenario_run_id' => $run->id,
                    'block_id' => '1063',
                    'published_version_id' => $publishedVersion->id,
                ],
            ],
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-field-key="current_block_id"', false)
            ->assertSee('#1063 · Пол заполнен')
            ->assertSee(ScenarioRun::STATUS_COMPLETED);
    }

    public function test_dialog_view_renders_non_v3_current_step_without_block_lookup(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $publishedVersion = $this->createPublishedScenarioVersion([
            'version' => 1,
            'blocks' => [],
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => $publishedVersion->scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reaction',
            'state_payload' => [],
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('awaiting_reaction · сценарий без V3-схемы');
    }

    public function test_dialog_view_marks_missing_current_v3_block_without_crashing(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $publishedVersion = $this->createPublishedScenarioVersion([
            'builder_v3_runtime' => [
                'blocks' => [],
            ],
        ]);

        ScenarioRun::query()->create([
            'scenario_code' => $publishedVersion->scenario->code,
            'dialog_id' => $dialog->id,
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => '999',
            'state_payload' => [
                'v3' => [
                    'schema_version' => 3,
                    'current_block_id' => '999',
                    'published_version_id' => $publishedVersion->id,
                ],
            ],
            'started_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('#999 · блок не найден');
    }

    public function test_dialog_view_escapes_legacy_dialog_field_keys_and_values(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->forceFill([
            'fields_payload' => [
                'legacy"field' => '<script>alert(1)</script>',
            ],
        ])->save();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-field-key="legacy&quot;field"', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('data-field-key="legacy"field"', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_active_admin_can_open_dialogs_inbox_page(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Диалоги')
            ->assertSee($dialog->contact->display_name);
    }

    public function test_dialogs_inbox_column_layout_config_matches_custom_manager_contract(): void
    {
        $config = collect(DialogResource::getDialogsTableColumnLayoutConfig())->keyBy('id');

        $this->assertSame([
            'selection',
            'contact',
            'status',
            'last_message',
            'stage',
            'assignee',
            'channel',
            'route',
            'actor',
            'activity',
            'id',
            'external_user_id',
            'external_username',
            'phone',
            'route_source',
            'external_chat_id',
        ], $config->keys()->all());

        $this->assertSame('__selection', $config['selection']['filament']);
        $this->assertSame(48, $config['selection']['defaultWidth']);
        $this->assertSame(48, $config['selection']['minWidth']);
        $this->assertTrue($config['selection']['defaultVisible']);
        $this->assertSame('preview-text', $config['last_message']['filament']);
        $this->assertSame(260, $config['last_message']['defaultWidth']);
        $this->assertSame(180, $config['last_message']['minWidth']);
        $this->assertTrue($config['last_message']['defaultVisible']);
        $this->assertSame(72, $config['id']['defaultWidth']);
        $this->assertSame(56, $config['id']['minWidth']);
        $this->assertFalse($config['external_user_id']['defaultVisible']);
    }

    public function test_dialogs_inbox_page_exposes_custom_column_manager_bootstrap(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('ab.dialogs.table.columns.v1.user.', false)
            ->assertSee('"id":"selection"', false)
            ->assertSee('"id":"last_message"', false)
            ->assertSee('"defaultWidth":260', false)
            ->assertSee("sortTable('contact_label')", false)
            ->assertSee('rowNavigationFallbackReady', false)
            ->assertSee('data-ac-dialogs-row-url', false)
            ->assertSee('data-ac-dialogs-tools', false)
            ->assertSee('installViewSwitchLoadingListener', false)
            ->assertSee('data-ac-dialogs-view-link', false)
            ->assertSee('wire:navigate.hover', false)
            ->assertSee('syncSelectionIndicatorVisibility', false);

        $themeOverrides = file_get_contents(resource_path('views/filament/components/admin-theme-overrides.blade.php'));

        $this->assertIsString($themeOverrides);
        $this->assertStringContainsString('syncSelectionIndicatorVisibility', $themeOverrides);
        $this->assertStringContainsString('shouldShowSelectionIndicator', $themeOverrides);
        $this->assertStringContainsString('node.hidden = !shouldShowSelectionIndicator', $themeOverrides);
        $this->assertStringContainsString('shouldIgnoreRowNavigationTarget', $themeOverrides);
        $this->assertStringContainsString('bindSelectionCellClickGuards', $themeOverrides);
        $this->assertStringContainsString('ensureSelectionCheckboxId', $themeOverrides);
        $this->assertStringContainsString('installSelectionCellClickGuard', $themeOverrides);
        $this->assertStringContainsString('resolveSelectionCellClickTarget', $themeOverrides);
        $this->assertStringContainsString('resolvePageSelectionClickTarget', $themeOverrides);
        $this->assertStringContainsString('togglePageSelectionCheckbox', $themeOverrides);
        $this->assertStringContainsString('syncPageSelectionCheckbox', $themeOverrides);
        $this->assertStringContainsString('selectionCellClickSuppressedUntil', $themeOverrides);
        $this->assertStringContainsString('ac-dialogs-selection-hitbox', $themeOverrides);
        $this->assertStringContainsString('acDialogsSelectionHitboxReady', $themeOverrides);
        $this->assertStringContainsString('td.fi-ta-selection-cell', $themeOverrides);
    }

    public function test_dialogs_inbox_preview_column_does_not_render_tooltips(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $this->createInboxDialog();

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('fi-ta-cell-preview-text', false)
            ->assertSee('Пользователь написал первым')
            ->getContent();

        $this->assertDoesNotMatchRegularExpression(
            '/<td[^>]*fi-ta-cell-preview-text(?:(?!<\\/td>).)*x-tooltip/s',
            $html,
        );
    }

    public function test_dialogs_inbox_table_uses_field_dictionary_labels(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'contact_id')
            ->firstOrFail()
            ->update(['name' => 'Клиент диалога']);
        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'channel_id')
            ->firstOrFail()
            ->update(['name' => 'Линия связи']);
        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail()
            ->update(['name' => 'Этап диалога']);
        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'last_message_at')
            ->firstOrFail()
            ->update(['name' => 'Сообщение диалога']);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableColumnExists(
                'contact_label',
                fn ($column): bool => $column->getLabel() === 'Клиент диалога',
                $dialog,
            )
            ->assertTableColumnExists(
                'channel_label',
                fn ($column): bool => $column->getLabel() === 'Линия связи',
                $dialog,
            )
            ->assertTableColumnExists(
                'stage',
                fn ($column): bool => $column->getLabel() === 'Этап диалога',
                $dialog,
            )
            ->assertTableColumnExists(
                'preview_text',
                fn ($column): bool => $column->getLabel() === 'Сообщение диалога',
                $dialog,
            );
    }

    public function test_dialogs_inbox_table_uses_field_dictionary_stage_option_label(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $dialog->forceFill(['stage' => Dialog::STAGE_TRANSFERRED_TO_MPP])->save();
        $dialog = $dialog->fresh();

        $stage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();
        $options = $stage->options;
        $options[4]['label'] = 'МПП из справочника';
        $stage->update(['options' => $options]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertSee('МПП из справочника');
    }

    public function test_dialogs_kanban_uses_field_dictionary_labels_and_stage_option_label(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $dialog->forceFill(['stage' => Dialog::STAGE_TRANSFERRED_TO_MPP])->save();

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'channel_id')
            ->firstOrFail()
            ->update(['name' => 'Линия связи']);

        $stage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();
        $options = $stage->options;
        $options[4]['label'] = 'МПП из справочника';
        $stage->update(['options' => $options]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('kanban'))
            ->assertOk()
            ->assertSee('Линия связи')
            ->assertSee('МПП из справочника')
            ->assertSee('data-ac-dialogs-view-link', false)
            ->assertSee('wire:navigate.hover', false);
    }

    public function test_dialogs_inbox_record_link_contains_back_to_dialogs_list(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $expectedUrl = DialogResource::getUrl('view', ['record' => $dialog]).'?'.http_build_query([
            'back_to' => DialogResource::getUrl('index'),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee($expectedUrl, false);
    }

    public function test_dialogs_inbox_page_enables_live_polling(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('wire:poll.10s', escape: false);
    }

    public function test_dialogs_inbox_uses_separate_hidden_columns_for_identity_and_phone_details(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Только контакт',
            'contactFirstName' => 'Только контакт',
            'externalUserId' => 'tg-user-555',
            'externalUsername' => 'dialog_hidden_user',
        ]);
        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 900 123 45 67',
        ])->save();
        $dialog = $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableColumnExists(
                'contact_label',
                fn ($column): bool => $column->getLabel() === 'Контакт' && $column->getDescriptionBelow() === null,
                $dialog,
            )
            ->assertTableColumnVisible('contact_label')
            ->assertTableColumnStateSet('contact_label', 'Только контакт', $dialog)
            ->assertTableColumnExists(
                'external_user_id',
                fn ($column): bool => $column->getLabel() === 'Внешний ID'
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('external_user_id', 'tg-user-555', $dialog)
            ->assertTableColumnExists(
                'external_username',
                fn ($column): bool => $column->getLabel() === 'Username'
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('external_username', '@dialog_hidden_user', $dialog)
            ->assertTableColumnExists(
                'phone_label',
                fn ($column): bool => $column->getLabel() === FieldDictionaryField::labelFor(FieldDictionaryField::ENTITY_DIALOG, 'phone', 'Номер телефона')
                    && $column->isToggleable()
                    && $column->isToggledHiddenByDefault(),
                $dialog,
            )
            ->assertTableColumnStateSet('phone_label', '+7 900 123 45 67', $dialog);
    }

    public function test_dialogs_inbox_does_not_show_identity_and_phone_details_inside_contact_column_by_default(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Чистый контакт',
            'contactFirstName' => 'Чистый контакт',
            'externalUserId' => 'hidden-route-777',
            'externalUsername' => 'hidden_dialog_username',
        ]);
        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 901 555 44 33',
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Чистый контакт')
            ->assertDontSee('hidden-route-777')
            ->assertDontSee('@hidden_dialog_username')
            ->assertDontSee('+7 901 555 44 33');
    }

    public function test_dialogs_inbox_shows_derived_stage_label_for_null_persisted_stage(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $dialog->forceFill([
            'stage' => null,
            'phone_confirmed_at' => now(),
        ])->save();
        $dialog = $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableColumnStateSet('stage', 'Телефон получен', $dialog);
    }

    public function test_dialogs_inbox_table_can_sort_by_contact_label(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $zetaDialog = $this->createInboxDialog([
            'contactName' => null,
            'contactFirstName' => 'Яков',
        ]);
        $alphaDialog = $this->createInboxDialog([
            'contactName' => null,
            'contactFirstName' => 'Анна',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->call('sortTable', 'contact_label', 'asc')
            ->assertCanSeeTableRecords([$alphaDialog, $zetaDialog], inOrder: true)
            ->call('sortTable', 'contact_label', 'desc')
            ->assertCanSeeTableRecords([$zetaDialog, $alphaDialog], inOrder: true);
    }

    public function test_dialogs_inbox_contact_sort_keeps_manual_requires_reply_filter_and_status_projection(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $openDialog = $this->createInboxDialog([
            'contactName' => 'Анна требует ответа',
        ]);
        $closedDialog = $this->createInboxDialog([
            'contactName' => 'Яков уже закрыт',
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Закрытый вопрос',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ответ уже отправлен',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->call('sortTable', 'contact_label', 'asc')
            ->assertSet('tableFilters.inbox_status.value', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertCanSeeTableRecords([$openDialog])
            ->assertCanNotSeeTableRecords([$closedDialog])
            ->assertTableColumnStateSet('inbox_status', 'Требует ответа', $openDialog);
    }

    public function test_dialogs_inbox_uses_current_dialog_identity_label_for_each_row(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog, $maxDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Telegram Клиент')
            ->assertSee('MAX Клиент');
    }

    public function test_dialogs_inbox_shows_telegram_account_dialog_even_when_route_is_not_sendable(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Inbox',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account inbox contact',
            'first_name' => 'Account inbox contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-inbox-1',
            'display_name' => 'Telegram Account Клиент',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-message-1',
            'text' => 'Новый account inbound',
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Account inbox contact')
            ->assertSee('Telegram Account Inbox')
            ->assertSee('Требует ответа')
            ->assertSee('Gateway не готов к исходящим ответам');
    }

    public function test_dialog_view_renders_media_badges_for_telegram_account_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Media',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account media contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-media-1',
            'display_name' => 'Telegram Account Медиа',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-media-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-media-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-media-message-1',
            'text' => 'Отправил медиа',
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                    ['type' => 'document', 'file_name' => 'offer.pdf'],
                ],
            ],
            'received_at' => now(),
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Фото')
            ->assertSee('Документ: offer.pdf')
            ->assertSee('Ожидает загрузки');

        $messages = $component->get('conversationMessages');

        $this->assertCount(1, $messages);
        $this->assertSame('Отправил медиа', $messages[0]['display_text']);
        $this->assertSame(['Фото', 'Документ: offer.pdf'], $messages[0]['media_badges']);
        $this->assertSame([
            ['label' => 'Ожидает загрузки x2', 'tone' => 'gray'],
        ], $messages[0]['media_state_badges']);
    }

    public function test_dialog_view_renders_operator_attachment_list_with_download_action_only_for_downloaded_file(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Attachment List',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account attachment contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-attachment-list-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-attachment-list-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-attachment-list-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-attachment-list-message-1',
            'text' => 'Документы клиента',
            'received_at' => now(),
        ]);
        $downloadedAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:document:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'original_filename' => 'offer.pdf',
            'mime_type' => 'application/pdf',
            'file_size_bytes' => 2048,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/offer.pdf',
            'sort_order' => 0,
        ]);
        $pendingAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '1:image:file-2',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-attachments"', false)
            ->assertDontSee('data-role="conversation-attachment-preview"', false)
            ->assertDontSee('data-media-viewer-type="pdf"', false)
            ->assertSee('data-role="conversation-attachment-download"', false)
            ->assertSee('offer.pdf')
            ->assertSee('application/pdf · 2 КБ')
            ->assertSee('photo.jpg')
            ->assertSee('Ожидает загрузки')
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $downloadedAttachment->id]), false)
            ->assertDontSee(route('admin.message-attachments.preview', ['attachment' => $downloadedAttachment->id]), false)
            ->assertDontSee(route('admin.message-attachments.download', ['attachment' => $pendingAttachment->id]), false);

        $messages = $component->get('conversationMessages');

        $this->assertTrue($messages[0]['media_items'][0]['is_downloadable']);
        $this->assertFalse($messages[0]['media_items'][0]['is_previewable']);
        $this->assertNull($messages[0]['media_items'][0]['preview_kind']);
        $this->assertFalse($messages[0]['media_items'][1]['is_downloadable']);
    }

    public function test_dialog_view_renders_previewable_video_as_inline_video_player(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Video Preview',
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Video preview contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'max-video-preview-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'max-video-preview-chat-1',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'max-video-preview-message-1',
            'provider_event_key' => 'max_bot:'.$channel->id.':'.$dialog->external_chat_id.':max-video-preview-message-1',
            'text' => 'Видео от клиента',
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'video',
                                'payload' => [
                                    'token' => 'max-video-preview-token',
                                ],
                                'thumbnail' => [
                                    'url' => 'https://pimg.mycdn.me/getImage?signatureToken=secret-poster-token',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'max-video-attachment-1',
            'provider_file_reference' => 'token:'.sha1('max-video-preview-token'),
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'original_filename' => null,
            'mime_type' => null,
            'extension' => null,
            'file_size_bytes' => null,
            'provider_metadata' => [
                'duration' => 20,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/clip.mp4',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-attachments"', false)
            ->assertSee('ac-message__attachments--inline-video', false)
            ->assertSee('data-role="conversation-video-player"', false)
            ->assertSee('data-role="conversation-attachment-video"', false)
            ->assertSee('data-role="conversation-attachment-preview"', false)
            ->assertSee('data-media-viewer-type="video"', false)
            ->assertSee('poster="'.route('admin.message-attachments.poster', ['attachment' => $attachment->id]).'"', false)
            ->assertDontSee('secret-poster-token', false)
            ->assertSee('controls', false)
            ->assertSee('playsinline', false)
            ->assertDontSee('data-role="conversation-media-gallery"', false)
            ->assertDontSee('data-role="conversation-attachment-status"', false)
            ->assertSee('data-video-title="Видео"', false)
            ->assertSee('aria-label="Видео"', false)
            ->assertSee('0:20')
            ->assertDontSee('Видео Видео')
            ->assertDontSee('0:20 · 4 КБ')
            ->assertDontSee('video/mp4 · 0:20 · 4 КБ')
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $attachment->id]), false)
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $attachment->id]), false);
    }

    public function test_dialog_view_renders_previewable_voice_as_inline_audio_player(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Voice Preview',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Voice preview contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-voice-preview-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-voice-preview-chat-1',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-voice-preview-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-voice-preview-message-1',
            'text' => 'Голосовое от клиента',
            'received_at' => now(),
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:voice:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VOICE,
            'original_filename' => 'voice.mp3',
            'mime_type' => 'audio/mpeg',
            'extension' => 'mp3',
            'file_size_bytes' => 2048,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/voice.mp3',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-attachments"', false)
            ->assertSee('wire:key="conversation-attachment-message:'.$message->id.'-'.$attachment->id.'"', false)
            ->assertSee('data-role="conversation-voice-player"', false)
            ->assertSee('wire:ignore', false)
            ->assertSee('wire:key="conversation-voice-player-message:'.$message->id.'-'.$attachment->id.'"', false)
            ->assertSee('data-role="conversation-attachment-audio"', false)
            ->assertSee('data-role="conversation-voice-toggle"', false)
            ->assertSee('data-role="conversation-voice-waveform"', false)
            ->assertSee('data-role="conversation-voice-time"', false)
            ->assertSee('preload="metadata"', false)
            ->assertDontSee('style="--ac-voice-bar:', false)
            ->assertDontSee('data-role="conversation-media-gallery"', false)
            ->assertDontSee('data-role="conversation-attachment-preview"', false)
            ->assertDontSee('data-media-viewer-type="audio"', false)
            ->assertDontSee('audio/mpeg · 2 КБ')
            ->assertSee('2 КБ')
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $attachment->id]), false)
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $attachment->id]), false);
    }

    public function test_dialog_view_renders_downloaded_max_audio_without_metadata_as_inline_audio_player(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);
        $message = Message::query()
            ->where('dialog_id', $dialog->id)
            ->firstOrFail();
        $message->update([
            'text' => null,
            'external_message_id' => 'max-audio-preview-message-1',
            'provider_event_key' => 'max-audio-preview-event-1',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => 'max-audio-preview-event-1',
            'provider_attachment_key' => 'max-audio-attachment-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_AUDIO,
            'original_filename' => null,
            'mime_type' => null,
            'extension' => null,
            'file_size_bytes' => null,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/94.mp3',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-attachments"', false)
            ->assertSee('data-role="conversation-voice-player"', false)
            ->assertSee('data-role="conversation-attachment-audio"', false)
            ->assertSee('data-voice-title="Аудио"', false)
            ->assertSee('aria-label="Аудио"', false)
            ->assertDontSee('data-role="conversation-media-badge"', false)
            ->assertDontSee('data-role="conversation-attachment-status"', false)
            ->assertDontSee('<div class="ac-message__text">Аудио</div>', false)
            ->assertDontSee('Аудио Аудио')
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $attachment->id]), false)
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $attachment->id]), false);
    }

    public function test_dialog_view_hides_generated_media_summary_for_downloaded_document_card(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);
        $message = Message::query()
            ->where('dialog_id', $dialog->id)
            ->firstOrFail();
        $message->update([
            'text' => null,
            'external_message_id' => 'max-document-card-message-1',
            'provider_event_key' => 'max-document-card-event-1',
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => 'max-document-card-event-1',
            'provider_attachment_key' => 'max-document-attachment-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
            'original_filename' => 'Comprovante de pagamento.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'file_size_bytes' => 1766,
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/95.pdf',
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-attachments"', false)
            ->assertSee('data-role="conversation-attachment"', false)
            ->assertSee('Comprovante de pagamento.pdf')
            ->assertSee('application/pdf · 1,7 КБ')
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $attachment->id]), false)
            ->assertDontSee('data-role="conversation-media-badge"', false)
            ->assertDontSee('data-role="conversation-attachment-status"', false)
            ->assertDontSee('<div class="ac-message__text">Документ</div>', false)
            ->assertDontSee('Документ: Comprovante de pagamento.pdf');
    }

    public function test_dialog_view_renders_outbound_max_buttons_as_disabled_preview(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $identity = $dialog->currentContactIdentity;
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => 'scenario___scenario_constructor_workspace',
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'max-button-preview-message-1',
            'provider_event_key' => null,
            'text' => 'QA MAX BTN-01: нажми кнопку ниже',
            'raw_payload' => [
                'v3' => [
                    'buttons' => [
                        'rows' => [
                            [
                                [
                                    'text' => 'Поделиться номером телефона',
                                    'type' => 'request_phone',
                                ],
                            ],
                        ],
                    ],
                ],
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'inline_keyboard',
                                'payload' => [
                                    'buttons' => [
                                        [
                                            [
                                                'text' => 'Поделиться номером телефона',
                                                'type' => 'request_contact',
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('QA MAX BTN-01: нажми кнопку ниже')
            ->assertSee('data-role="conversation-button-preview"', false)
            ->assertSee('Отправленные кнопки')
            ->assertSee('data-role="conversation-button-chip"', false)
            ->assertSee('data-button-type="request_contact"', false)
            ->assertSee('Поделиться номером телефона')
            ->assertSee('Запрос телефона')
            ->assertSee('disabled', false)
            ->assertDontSee('href="Поделиться номером телефона"', false);

        $this->assertDatabaseHas('messages', [
            'id' => $message->id,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
        ]);
    }

    public function test_dialog_view_live_refresh_defers_during_media_playback_and_text_selection(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('hasActiveMediaPlayback()', false)
            ->assertSee('hasActiveConversationSelection()', false)
            ->assertSee('shouldDeferLiveRefresh()', false)
            ->assertSee('this.shouldDeferLiveRefresh()', false);
    }

    public function test_dialog_view_renders_previewable_image_as_gallery_without_technical_labels(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Image Gallery',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Image gallery contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-image-gallery-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-image-gallery-chat-1',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-image-gallery-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-image-gallery-message-1',
            'text' => 'Подпись под фотографией',
            'received_at' => now(),
        ]);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:image:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'visible-file-name.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/visible-file-name.jpg',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-media-gallery"', false)
            ->assertSee('data-count="1"', false)
            ->assertSee('data-role="conversation-attachment-preview"', false)
            ->assertSee('data-media-viewer-trigger', false)
            ->assertSee('data-media-viewer-type="image"', false)
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $attachment->id]), false)
            ->assertSee(route('admin.message-attachments.download', ['attachment' => $attachment->id]), false)
            ->assertSee('Подпись под фотографией')
            ->assertDontSee('data-role="conversation-media-badge"', false)
            ->assertDontSee('data-role="conversation-media-status-badge"', false)
            ->assertDontSee('data-role="conversation-attachment-download"', false)
            ->assertDontSee('visible-file-name.jpg');

        $html = $component->html();

        $this->assertLessThan(
            strpos($html, 'Подпись под фотографией'),
            strpos($html, 'data-role="conversation-media-gallery"'),
        );
    }

    public function test_dialog_view_renders_mixed_image_and_video_as_single_gallery(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Mixed Gallery',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Mixed gallery contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-mixed-gallery-1',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-mixed-gallery-chat-1',
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-mixed-gallery-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-mixed-gallery-message-1',
            'text' => "🎉 Фото + видео + текст.\n\nжирный",
            'received_at' => now(),
        ]);
        $imageAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '0:image:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'mixed-photo.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/mixed-photo.jpg',
            'sort_order' => 0,
        ]);
        $videoAttachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $channel->id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => '1:video:file-2',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO,
            'original_filename' => 'mixed-video.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => 32768,
            'provider_metadata' => [
                'duration' => 2,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/mixed-video.mp4',
            'sort_order' => 1,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-media-gallery"', false)
            ->assertSee('data-count="2"', false)
            ->assertSee('data-media-viewer-type="image"', false)
            ->assertSee('data-media-viewer-type="video"', false)
            ->assertSee('ac-message-gallery__item--video', false)
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $imageAttachment->id]), false)
            ->assertSee(route('admin.message-attachments.preview', ['attachment' => $videoAttachment->id]), false)
            ->assertSee('🎉 Фото + видео + текст.')
            ->assertSee('жирный')
            ->assertDontSee('data-role="conversation-video-player"', false)
            ->assertDontSee('data-role="conversation-attachment-video"', false)
            ->assertDontSee('ac-message__attachments--inline-video', false)
            ->assertDontSee('data-role="conversation-attachments"', false)
            ->assertDontSee('mixed-photo.jpg')
            ->assertDontSee('mixed-video.mp4');

        $html = $component->html();

        $this->assertLessThan(
            strpos($html, '🎉 Фото + видео + текст.'),
            strpos($html, 'data-role="conversation-media-gallery"'),
        );
    }

    public function test_dialog_view_renders_grouped_images_as_single_gallery_grid(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);
        $firstMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->firstOrFail();
        $firstMessage->update([
            'text' => null,
            'provider_group_key' => 'rendered-album-gallery',
            'provider_event_key' => 'rendered-album-gallery-event-1',
            'external_message_id' => 'rendered-album-gallery-message-1',
        ]);
        $secondMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => null,
            'received_at' => $firstMessage->received_at?->copy()->addSecond() ?? now()->addSecond(),
            'external_message_id' => 'rendered-album-gallery-message-2',
            'provider_event_key' => 'rendered-album-gallery-event-2',
            'provider_group_key' => 'rendered-album-gallery',
        ]);

        foreach ([$firstMessage, $secondMessage] as $index => $message) {
            MessageAttachment::factory()->create([
                'message_id' => $message->id,
                'channel_id' => $dialog->channel_id,
                'provider' => MessageAttachment::PROVIDER_MAX_BOT,
                'provider_event_key' => $message->provider_event_key,
                'provider_attachment_key' => $index.':image:file-'.$index,
                'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
                'original_filename' => 'gallery-file-'.($index + 1).'.jpg',
                'mime_type' => 'image/jpeg',
                'extension' => 'jpg',
                'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
                'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/gallery-file-'.($index + 1).'.jpg',
            ]);
        }

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-media-gallery"', false)
            ->assertSee('data-count="2"', false)
            ->assertDontSee('data-role="conversation-media-badge"', false)
            ->assertDontSee('data-role="conversation-media-status-badge"', false)
            ->assertDontSee('data-role="conversation-attachment-download"', false)
            ->assertDontSee('gallery-file-1.jpg')
            ->assertDontSee('gallery-file-2.jpg');

        $messages = $component->get('conversationMessages');

        $this->assertCount(1, $messages);
        $this->assertSame([$firstMessage->id, $secondMessage->id], $messages[0]['message_ids']);
        $this->assertCount(2, $messages[0]['media_items']);
    }

    public function test_dialogs_inbox_preview_meta_shows_media_download_placeholder_for_telegram_account_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Placeholder Inbox',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account placeholder contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-placeholder-1',
            'display_name' => 'Telegram Account Placeholder',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-placeholder-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        $this->createDialogMessage($dialog, [
            'contact_identity_id' => $identity->id,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'tg-account-placeholder-message-1',
            'provider_event_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id.':tg-account-placeholder-message-1',
            'text' => null,
            'raw_payload' => [
                'media' => [
                    ['type' => 'photo'],
                ],
            ],
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Фото')
            ->assertSee('Ожидает загрузки');
    }

    public function test_dialog_view_renders_peer_sync_state_for_telegram_account_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->account()->create([
            'name' => 'Telegram Account Sync',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Account sync contact',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'tg-account-sync-1',
            'display_name' => 'Telegram Account Sync Клиент',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'tg-account-sync-chat-1',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);
        ChannelPeerSyncState::query()->create([
            'channel_id' => $channel->id,
            'peer_key' => 'telegram_account:'.$channel->id.':'.$dialog->external_chat_id,
            'external_chat_id' => $dialog->external_chat_id,
            'backfill_status' => ChannelPeerSyncState::BACKFILL_STATUS_COMPLETE,
            'oldest_imported_message_id' => '900001',
            'latest_observed_message_id' => '900050',
            'history_complete_at' => now()->setDate(2026, 4, 23)->setTime(13, 15, 0),
            'last_sync_error' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('selectTab', SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS)
            ->assertSeeHtml('data-role="dialog-peer-sync-status"')
            ->assertSee('Завершена')
            ->assertSee('23.04.2026 13:15')
            ->assertSee('900001')
            ->assertSee('900050');
    }

    public function test_employee_can_open_dialog_view_page_with_reply_composer(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($user)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="conversation-reply-form"', false);
    }

    public function test_dialog_view_uses_current_dialog_identity_label_in_contact_summary(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('Telegram Клиент');
    }

    public function test_dialog_view_exposes_live_refresh_polling_configuration(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-poll-interval-ms="5000"', escape: false)
            ->assertSee('refreshDialogViewData', escape: false)
            ->assertSee('hasActiveReplyComposer()', escape: false)
            ->assertSee("textarea.dataset.manualResized === '1'", escape: false)
            ->assertSee("querySelector('[data-role=conversation-thread]')", escape: false)
            ->assertSee('window.requestAnimationFrame(() => this.scrollToBottom())', escape: false)
            ->assertDontSee('[data-role=\\"conversation-thread\\"]', escape: false);
    }

    public function test_dialog_view_renders_one_click_status_toggle_for_pending_inbox_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Статус')
            ->assertSee('Требует ответа')
            ->assertSee('Не требует ответа')
            ->assertSee('data-role="dialog-general-tab"', escape: false)
            ->assertSee('data-field-key="status"', escape: false)
            ->assertSee('data-role="dialog-inbox-status-toggle"', escape: false)
            ->assertSee('data-role="dialog-inbox-status-current"', escape: false)
            ->assertSee('wire:click="setDialogInboxStatus', escape: false)
            ->assertSee('Сменить на: Не требует ответа')
            ->assertDontSee('data-role="dialog-params-card"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-option"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-select"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-help"', escape: false)
            ->assertDontSee('data-role="dialog-inbox-status-help-panel"', escape: false)
            ->assertDontSee('Новое входящее сообщение автоматически вернёт диалог в статус «Требует ответа».')
            ->assertDontSee('<p class="ac-field-help">', escape: false)
            ->assertDontSee('Рабочее место оператора')
            ->assertDontSee('Здесь показаны только сообщения текущего диалога в хронологическом порядке.');
    }

    public function test_dialog_view_renders_assignee_toggle_in_system_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Сотрудник Диалога',
            'last_name' => 'Иванов',
        ]);
        $dialog = $this->createInboxDialog([
            'assignedUserId' => $assignee->id,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-general-tab"', escape: false)
            ->assertSee('data-field-key="assigned_user_id"', escape: false)
            ->assertSee('data-role="dialog-assignee-toggle"', escape: false)
            ->assertSee('data-role="dialog-assignee-current"', escape: false)
            ->assertSee('wire:click="openDialogAssigneeEditor"', escape: false)
            ->assertSee('Сотрудник Диалога Иванов')
            ->assertDontSee('data-role="dialog-assignee-editor"', escape: false)
            ->assertDontSee('data-role="dialog-assignee-dialog"', escape: false);
    }

    public function test_dialog_view_can_update_contact_assignee_from_system_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Новый Ответственный',
            'last_name' => 'Петров',
        ]);
        $dialog = $this->createInboxDialog([
            'assignedUserId' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="dialog-assignee-toggle"', escape: false)
            ->call('openDialogAssigneeEditor')
            ->assertSet('isDialogAssigneeEditing', true)
            ->assertSet('selectedDialogAssigneeId', '')
            ->assertSee('data-role="dialog-assignee-editor"', escape: false)
            ->assertSee('data-role="dialog-assignee-select"', escape: false)
            ->assertDontSee('data-role="dialog-save-assignee-button"', escape: false)
            ->assertSee('Новый Ответственный Петров')
            ->set('selectedDialogAssigneeId', (string) $assignee->id)
            ->assertSet('dialogFieldDraftDirty', true)
            ->assertSee('data-role="dialog-field-savebar"', escape: false)
            ->call('saveDialogFieldDraftValues')
            ->assertNotified()
            ->assertSet('isDialogAssigneeEditing', false)
            ->assertSee('Новый Ответственный Петров');

        $this->assertSame($assignee->id, $dialog->contact->fresh()->assigned_user_id);
    }

    public function test_dialog_view_can_release_contact_assignee_from_system_fields(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'name' => 'Текущий Ответственный',
        ]);
        $dialog = $this->createInboxDialog([
            'assignedUserId' => $owner->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('openDialogAssigneeEditor')
            ->assertSet('isDialogAssigneeEditing', true)
            ->assertSet('selectedDialogAssigneeId', (string) $owner->id)
            ->assertSee('data-role="dialog-assignee-select"', escape: false)
            ->assertDontSee('data-role="dialog-save-assignee-button"', escape: false)
            ->assertSee('Свободен')
            ->set('selectedDialogAssigneeId', '')
            ->assertSet('dialogFieldDraftDirty', true)
            ->assertSee('data-role="dialog-field-savebar"', escape: false)
            ->call('saveDialogFieldDraftValues')
            ->assertNotified()
            ->assertSet('isDialogAssigneeEditing', false)
            ->assertSee('Свободен');

        $this->assertNull($dialog->contact->fresh()->assigned_user_id);
    }

    public function test_dialog_view_renders_assignee_as_text_when_contact_ownership_is_forbidden(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Видимый Ответственный',
        ]);
        $dialog = $this->createInboxDialog([
            'assignedUserId' => $assignee->id,
        ]);

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'contacts.edit')
            ->update(['granted' => false]);

        $employee = User::query()->findOrFail($employee->id);

        $this->actingAs($employee)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-field-key="assigned_user_id"', escape: false)
            ->assertSee('Видимый Ответственный')
            ->assertDontSee('data-role="dialog-assignee-toggle"', escape: false)
            ->assertDontSee('wire:click="openDialogAssigneeEditor"', escape: false);
    }

    public function test_dialog_view_renders_dialog_stage_strip_instead_of_stage_select(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $dialog->contact()->update([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $dialog->forceFill([
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPL,
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-stage-strip"', false)
            ->assertSee('data-current-tone="warning"', false)
            ->assertSee('class="ac-dialog-stage-strip ac-dialog-summary__stage"', false)
            ->assertSee('data-role="dialog-stage-step"', false)
            ->assertSee('Новый диалог')
            ->assertSee('Телефон получен')
            ->assertSee('Данные собраны')
            ->assertSee('МПЛ взял в работу')
            ->assertSee('Передан в МПП')
            ->assertSee('data-state="current"', false)
            ->assertSee('data-state="available"', false)
            ->assertDontSee('<p class="ac-dialog-stage-strip__label">', false)
            ->assertDontSee('class="ac-dialog-stage-strip ac-surface__divider"', false)
            ->assertDontSee('data-role="dialog-stage-select"', false);
    }

    public function test_dialog_view_keeps_clickable_previous_stage_colored_as_completed(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $dialog->contact()->update([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'data_collection_completed_at' => now(),
        ]);
        $dialog->forceFill([
            'stage' => Dialog::STAGE_TRANSFERRED_TO_MPP,
        ])->save();

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-current-tone="primary"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~<button[^>]*data-role="dialog-stage-step"[^>]*data-state="completed"[^>]*data-tone="warning"[^>]*>\s*<span class="ac-dialog-stage-step__label">\s*МПЛ взял в работу\s*</span>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~<button[^>]*data-role="dialog-stage-step"[^>]*data-state="current"[^>]*data-tone="primary"[^>]*>\s*<span class="ac-dialog-stage-step__label">\s*Передан в МПП\s*</span>~su',
            $html,
        );
    }

    public function test_dialog_view_uses_yellow_highlight_buttons_and_green_send_button(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning[^"]*"[^>]*>\s*Открыть контакт\s*</a>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning-soft[^"]*"[^>]*>\s*Обычный вид\s*</button>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--warning-soft[^"]*"[^>]*>\s*Просто текст\s*</button>~su',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '~class="[^"]*ac-button[^"]*ac-button--success[^"]*"[^>]*>\s*<span[^>]*>Отправить</span>~su',
            $html,
        );
    }

    public function test_dialog_view_uses_field_dictionary_labels_in_side_panel(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'contact_id')
            ->firstOrFail()
            ->update(['name' => 'Клиент диалога']);
        FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'channel_id')
            ->firstOrFail()
            ->update(['name' => 'Канал обращения']);
        $stage = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('field_key', 'stage')
            ->firstOrFail();
        $options = $stage->options;
        $options[4]['label'] = 'МПП из справочника';
        $stage->update(['options' => $options]);
        $telegramDialog->forceFill(['stage' => Dialog::STAGE_TRANSFERRED_TO_MPP])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('Клиент диалога')
            ->assertSee('Канал обращения')
            ->assertSee('МПП из справочника')
            ->assertSee('data-field-key="contact_id"', false)
            ->assertSee('data-field-key="channel_id"', false)
            ->assertDontSee('Имя из мессенджера')
            ->assertDontSee('<p class="ac-surface__subtitle">', false)
            ->assertSee('Telegram Клиент');
    }

    public function test_dialog_view_renders_dialog_phone_from_dictionary_without_contact_phone_mix(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $contact = $dialog->contact()->firstOrFail();

        $dialog->forceFill([
            'confirmed_phone_raw' => '+7 999 000 00 01',
            'confirmed_phone_normalized' => '+79990000001',
        ])->save();

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 900 111 22 33',
            'phone_normalized' => '+79001112233',
            'is_primary' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Телефон')
            ->assertSee('+7 999 000 00 01')
            ->assertDontSee('Телефоны контакта')
            ->assertDontSee('+7 926 352 71 11')
            ->assertDontSee('+7 900 111 22 33')
            ->assertDontSee('<p class="ac-dialog-summary__section-title">Диалог</p>', false)
            ->assertDontSee('<p class="ac-dialog-summary__section-title">Контакт и канал</p>', false);
    }

    public function test_dialog_view_renders_identity_avatar_in_dialog_header_when_avatar_exists(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();

        Storage::disk('contact_avatars')->put('contact-identities/'.$identity->id.'/avatar/test-avatar.jpg', 'avatar-image');

        $identity->forceFill([
            'avatar_path' => 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_renders_identity_avatar_in_dialog_header_when_only_legacy_public_avatar_exists(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();

        Storage::disk('public')->put('contact-identities/'.$identity->id.'/avatar/test-avatar.jpg', 'avatar-image');

        $identity->forceFill([
            'avatar_path' => 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg',
            'avatar_updated_at' => now(),
        ])->save();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_falls_back_to_legacy_public_avatar_when_contact_avatar_storage_read_fails(): void
    {
        Storage::fake('contact_avatars');
        Storage::fake('public');

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $identity = $dialog->currentContactIdentity()->firstOrFail();
        $avatarPath = 'contact-identities/'.$identity->id.'/avatar/test-avatar.jpg';

        Storage::disk('public')->put($avatarPath, 'avatar-image');

        $identity->forceFill([
            'avatar_path' => $avatarPath,
            'avatar_updated_at' => now(),
        ])->save();

        $avatarStorage = \Mockery::mock(ContactIdentityAvatarStorage::class);
        $avatarStorage->shouldReceive('exists')
            ->once()
            ->with($avatarPath)
            ->andThrow(new \RuntimeException('Temporary object storage failure.'));
        $avatarStorage->shouldReceive('url')->never();

        app()->instance(ContactIdentityAvatarStorage::class, $avatarStorage);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-image"', false);
    }

    public function test_dialog_view_renders_avatar_fallback_when_identity_avatar_is_missing(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$telegramDialog] = $this->createMultiChannelDialogsForContactLabel();

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $telegramDialog]))
            ->assertOk()
            ->assertSee('data-role="dialog-contact-avatar-fallback"', false)
            ->assertSee('TК');
    }

    public function test_employee_can_open_dialogs_inbox_page(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);

        $this->actingAs($user)
            ->get(DialogResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Диалоги');
    }

    public function test_employee_without_dialogs_view_cannot_open_dialog_pages(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $dialog = $this->createDialogWithMessages();

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.view')
            ->update(['granted' => false]);

        $user = User::query()->findOrFail($user->id);

        $this->actingAs($user)
            ->get(DialogResource::getUrl('index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertForbidden();
    }

    public function test_dialog_policy_and_reply_helper_respect_disabled_employee_matrix_values(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $dialog = $this->createDialogWithMessages();

        DB::table('role_permissions')
            ->where('role', User::ROLE_EMPLOYEE)
            ->where('permission_key', 'dialogs.edit')
            ->update(['granted' => false]);

        $user = User::query()->findOrFail($user->id);

        $this->assertTrue(Gate::forUser($user)->allows('viewAny', Dialog::class));
        $this->assertTrue(Gate::forUser($user)->allows('view', $dialog));
        $this->assertFalse(Gate::forUser($user)->allows('update', $dialog));
        $this->assertFalse($user->canReplyInDialogs());
    }

    public function test_dialog_delete_policy_uses_hidden_dialogs_delete_permission(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);
        $dialog = $this->createDialogWithMessages();

        $this->assertTrue(Gate::forUser($admin)->allows('delete', $dialog));
        $this->assertTrue(Gate::forUser($admin)->allows('deleteAny', Dialog::class));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $dialog));
        $this->assertFalse(Gate::forUser($employee)->allows('deleteAny', Dialog::class));
    }

    public function test_admin_can_bulk_delete_selected_dialogs_from_table(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $firstDialog = $this->createDialogWithMessages();
        $secondDialog = $this->createDialogWithMessages();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableBulkActionVisible('delete')
            ->assertTableBulkActionHasLabel('delete', 'Удалить выбранные')
            ->callTableBulkAction('delete', [$firstDialog, $secondDialog])
            ->assertHasNoTableBulkActionErrors()
            ->assertSet('selectedTableRecords', [])
            ->assertSet('deselectedTableRecords', [])
            ->assertSet('isTrackingDeselectedTableRecords', false);

        $this->assertModelMissing($firstDialog);
        $this->assertModelMissing($secondDialog);
    }

    public function test_dialogs_inbox_clears_stale_selection_when_table_context_changes(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $firstDialog = $this->createInboxDialog([
            'contactName' => 'Первый диалог',
        ]);
        $secondDialog = $this->createInboxDialog([
            'contactName' => 'Второй диалог',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->set('selectedTableRecords', [(string) $firstDialog->getKey()])
            ->set('deselectedTableRecords', [(string) $secondDialog->getKey()])
            ->set('isTrackingDeselectedTableRecords', true)
            ->call('sortTable', 'contact_label', 'asc')
            ->assertSet('selectedTableRecords', [])
            ->assertSet('deselectedTableRecords', [])
            ->assertSet('isTrackingDeselectedTableRecords', false);
    }

    public function test_dialogs_inbox_clears_stale_selection_when_selected_row_disappears_on_refresh(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог уйдёт из списка',
        ]);
        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('received_at')
            ->latest('id')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertCanSeeTableRecords([$dialog])
            ->set('selectedTableRecords', [(string) $dialog->getKey()]);

        $dialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $latestInbound->id,
        ])->save();

        $component
            ->call('$refresh')
            ->assertCanNotSeeTableRecords([$dialog])
            ->assertSet('selectedTableRecords', [])
            ->assertSet('deselectedTableRecords', [])
            ->assertSet('isTrackingDeselectedTableRecords', false);
    }

    public function test_dialogs_inbox_keeps_visible_selection_when_table_refreshes(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог остаётся в списке',
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$dialog])
            ->set('selectedTableRecords', [(string) $dialog->getKey()])
            ->call('$refresh')
            ->assertCanSeeTableRecords([$dialog])
            ->assertSet('selectedTableRecords', [(string) $dialog->getKey()])
            ->assertSet('deselectedTableRecords', [])
            ->assertSet('isTrackingDeselectedTableRecords', false);
    }

    public function test_dialogs_inbox_opens_without_default_status_filter(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $closedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ уже дан',
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Вопрос закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ответ уже отправлен',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertTableFilterExists('inbox_status')
            ->assertSet('tableFilters.inbox_status.value', null)
            ->assertCanSeeTableRecords([$openDialog, $closedDialog]);
    }

    public function test_dialogs_inbox_can_filter_requires_manual_reply_dialogs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $closedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ уже дан',
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Вопрос закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ответ уже отправлен',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertCanSeeTableRecords([$openDialog])
            ->assertCanNotSeeTableRecords([$closedDialog]);
    }

    public function test_dialogs_inbox_status_filter_supports_all_inbox_states(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $requiresReplyDialog = $this->createInboxDialog([
            'contactName' => 'Требует ответа',
        ]);
        $notRequiredDialog = $this->createInboxDialog([
            'contactName' => 'Не требует ответа',
        ]);
        $noNewDialog = $this->createInboxDialog([
            'contactName' => 'Нет новых',
        ]);

        $dismissedInbound = Message::query()
            ->where('dialog_id', $notRequiredDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $notRequiredDialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $dismissedInbound->id,
        ])->save();

        $closedInbound = Message::query()
            ->where('dialog_id', $noNewDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $this->createDialogMessage($noNewDialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'На это сообщение уже ответили',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->assertCanSeeTableRecords([$notRequiredDialog])
            ->assertCanNotSeeTableRecords([$requiresReplyDialog, $noNewDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_NO_NEW)
            ->assertCanSeeTableRecords([$noNewDialog])
            ->assertCanNotSeeTableRecords([$requiresReplyDialog, $notRequiredDialog]);
    }

    public function test_dialogs_inbox_requires_reply_filter_hides_manually_dismissed_dialogs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $openDialog = $this->createInboxDialog([
            'contactName' => 'Нужен ответ',
        ]);
        $dismissedDialog = $this->createInboxDialog([
            'contactName' => 'Ответ не нужен',
        ]);
        $dismissedInbound = Message::query()
            ->where('dialog_id', $dismissedDialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $dismissedDialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $dismissedInbound->id,
        ])->save();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertCanSeeTableRecords([$openDialog])
            ->assertCanNotSeeTableRecords([$dismissedDialog]);
    }

    public function test_dialogs_inbox_status_is_scoped_to_dialog_not_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Один контакт',
        ]);
        $telegram = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
            'external_user_id' => 'tg-scope',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'platform' => $max->platform,
            'external_user_id' => 'max-scope',
        ]);

        $openDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'chat-open-dialog',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);
        $closedDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => 'chat-closed-dialog',
            'last_message_at' => now(),
            'last_inbound_at' => now()->subMinutes(2),
            'last_outbound_at' => now(),
        ]);

        $this->createDialogMessage($openDialog, [
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegram->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Диалог без ручного ответа',
            'received_at' => now()->subMinute(),
        ]);

        $closedInbound = $this->createDialogMessage($closedDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Диалог уже закрыт',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($closedDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $closedInbound->id,
            'text' => 'Ручной ответ для второго диалога',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->assertCanSeeTableRecords([$openDialog, $closedDialog])
            ->assertSee('Требует ответа')
            ->assertSee('Нет новых')
            ->assertSee('Диалог без ручного ответа')
            ->assertSee('Ручной ответ для второго диалога');
    }

    public function test_dialogs_inbox_hides_dialogs_of_merged_secondary_contacts(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $visibleDialog = $this->createInboxDialog([
            'contactName' => 'Root contact',
        ]);

        $root = Contact::factory()->create([
            'name' => 'Основной контакт',
        ]);
        $merged = Contact::factory()->create([
            'name' => 'Архивный дубль',
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $channel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'merged-max',
        ]);
        $hiddenDialog = Dialog::factory()->create([
            'contact_id' => $merged->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-merged-hidden',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->createDialogMessage($hiddenDialog, [
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Merged dialog should stay hidden',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$visibleDialog])
            ->assertCanNotSeeTableRecords([$hiddenDialog]);
    }

    public function test_dialogs_inbox_filters_support_my_and_unassigned_dialogs(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $otherAdmin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $myDialog = $this->createInboxDialog([
            'contactName' => 'Мой диалог',
            'assignedUserId' => $admin->id,
        ]);
        $freeDialog = $this->createInboxDialog([
            'contactName' => 'Свободный диалог',
            'assignedUserId' => null,
        ]);
        $foreignDialog = $this->createInboxDialog([
            'contactName' => 'Чужой диалог',
            'assignedUserId' => $otherAdmin->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('assigned_to_me')
            ->assertCanSeeTableRecords([$myDialog])
            ->assertCanNotSeeTableRecords([$freeDialog, $foreignDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('unassigned_dialogs')
            ->assertCanSeeTableRecords([$freeDialog])
            ->assertCanNotSeeTableRecords([$myDialog, $foreignDialog]);
    }

    public function test_dialogs_inbox_channel_and_route_filters_work(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        $readyDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-ready-chat',
            'contactName' => 'Telegram ready',
        ]);
        $routeProblemDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Broken',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => '',
            'contactName' => 'Telegram broken',
        ]);
        $tokenlessDialog = $this->createInboxDialog([
            'channelName' => 'Telegram No Token',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-no-token-chat',
            'contactName' => 'Telegram no token',
            'hasToken' => false,
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('route_ready')
            ->assertCanSeeTableRecords([$readyDialog])
            ->assertCanNotSeeTableRecords([$routeProblemDialog, $tokenlessDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('route_problem')
            ->assertCanSeeTableRecords([$routeProblemDialog, $tokenlessDialog])
            ->assertCanNotSeeTableRecords([$readyDialog])
            ->assertSee('Нет токена');

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->filterTable('channel_id', $readyDialog->channel_id)
            ->assertCanSeeTableRecords([$readyDialog])
            ->assertCanNotSeeTableRecords([$routeProblemDialog]);
    }

    public function test_dialogs_inbox_shows_preview_sender_badge_and_links_to_dialog_view(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с автоответом',
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'text' => 'Автоответ системы',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Автоответ системы')
            ->assertSee('Автоответчик')
            ->assertSee(DialogResource::getUrl('view', ['record' => $dialog]), escape: false);
    }

    public function test_dialogs_inbox_shows_bitrix24_sender_badge_for_bitrix24_openlines_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с Bitrix24',
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:preview-1',
            'text' => 'Ответ из Bitrix24',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->removeTableFilter('inbox_status')
            ->assertSee('Ответ из Bitrix24')
            ->assertSee('Bitrix24');
    }

    public function test_dialogs_inbox_preview_ignores_dialog_status_history_note(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог со статусом',
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Пользователь написал первым')
            ->assertDontSee('Оператор изменил статус диалога');
    }

    public function test_dialogs_inbox_preview_keeps_latest_legacy_message_with_null_kind(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Диалог с legacy preview',
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Старое обычное сообщение',
            'received_at' => now()->subMinutes(2),
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => null,
            'text' => 'Последнее legacy сообщение',
            'received_at' => now()->subMinute(),
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'text' => 'Оператор изменил статус диалога',
            'received_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('index'));

        $response->assertOk()
            ->assertSee('Последнее legacy сообщение')
            ->assertDontSee('Старое обычное сообщение')
            ->assertDontSee('Оператор изменил статус диалога');
    }

    public function test_dialogs_inbox_keeps_system_unsubscribe_preview_without_marking_dialog_as_requires_reply(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog([
            'contactName' => 'Системный диалог',
            'channelName' => 'Telegram Support',
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'reply_to_message_id' => $latestInbound->id,
            'text' => 'Оператор уже ответил',
            'received_at' => now()->subSeconds(10),
        ]);

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->filterTable('inbox_status', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertCanNotSeeTableRecords([$dialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$dialog])
            ->assertSee('Клиент заблокировал бота')
            ->assertSee('Система')
            ->assertSee('Нет новых');
    }

    public function test_dialog_view_renders_telegram_unsubscribe_as_system_message_badge(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $dialog->channel()->update([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'name' => 'Telegram Support',
            'credentials' => ['token' => 'telegram-token'],
        ]);
        $dialog->forceFill([
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ])->save();

        $this->createDialogMessage($dialog, [
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_SYSTEM_EVENT,
            'system_event_code' => Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_TELEGRAM_BOT_SUBSCRIPTION,
            'text' => null,
            'received_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Клиент заблокировал бота')
            ->assertSee('Системное уведомление')
            ->assertDontSee('data-role="conversation-sender"', false)
            ->assertDontSee('Входящее');
    }

    public function test_dialog_view_renders_dialog_stage_history_as_system_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact?->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'text' => 'Оператор Герман изменил этап диалога: Данные собраны -> МПЛ взял в работу',
            'received_at' => now(),
        ]);

        $html = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]))
            ->assertOk()
            ->assertSee('Системное уведомление')
            ->assertDontSee('data-role="conversation-sender"', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '~data-kind="outbound_dialog_status_change"[^>]*class="[^"]*ac-message--system[^"]*"~su',
            $html,
        );
        $this->assertDoesNotMatchRegularExpression(
            '~data-kind="outbound_dialog_status_change"[^>]*class="[^"]*ac-message--outbound[^"]*"~su',
            $html,
        );
    }

    public function test_dialog_view_route_status_matches_inbox_route_badge_for_same_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $readyDialog = $this->createInboxDialog([
            'channelName' => 'Telegram Ready View',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-ready-view',
            'contactName' => 'Telegram ready view',
        ]);
        $problemDialog = $this->createInboxDialog([
            'channelName' => 'Telegram No Token View',
            'platform' => Channel::PLATFORM_TELEGRAM,
            'externalChatId' => 'tg-no-token-view',
            'contactName' => 'Telegram no token view',
            'hasToken' => false,
        ]);

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $readyDialog]))
            ->assertOk()
            ->assertSee('Маршрут готов');

        $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $problemDialog]))
            ->assertOk()
            ->assertSee('Нет токена');
    }

    public function test_dialogs_inbox_searches_contact_profile_identity_chat_and_phone_without_legacy_name(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $targetDialog = $this->createInboxDialog([
            'contactName' => 'Legacy target name',
            'contactFirstName' => 'Герман',
            'contactLastName' => 'Абрикосов',
            'externalUserId' => 'target-user-100',
            'externalUsername' => 'german_abrikosov',
            'displayName' => 'Telegram Клиент ivan 1779183',
            'externalChatId' => 'target-chat-100',
        ]);
        $otherDialog = $this->createInboxDialog([
            'contactName' => 'Legacy other name',
            'contactFirstName' => 'Другой',
            'contactLastName' => 'Контакт',
            'externalUserId' => 'other-user-200',
            'externalUsername' => 'other_target',
            'displayName' => 'MAX Клиент',
            'externalChatId' => 'other-chat-200',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $targetDialog->contact_id,
            'phone_raw' => '+420 773 177 918',
            'phone_normalized' => '+420773177918',
            'is_primary' => true,
        ]);
        ContactIdentity::factory()->create([
            'contact_id' => $targetDialog->contact_id,
            'channel_id' => $targetDialog->channel_id,
            'platform' => $targetDialog->channel->platform,
            'external_user_id' => 'old-target-user',
            'external_username' => 'old_target_username',
            'display_name' => 'Old Target Identity',
        ]);

        $targetDialog->forceFill([
            'confirmed_phone_raw' => '+55 (11) 91234-5678',
            'confirmed_phone_normalized' => '+5511912345678',
        ])->save();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('  Герман Абрикосов  ')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('Абрикосов Герман')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('german_abrikosov')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('@german_abrikosov')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('target-user-100')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('telegram клиент')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('ivan 177918')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('target-chat-100')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('+420 773 177 918')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('177918')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('912345678')
            ->assertCanSeeTableRecords([$targetDialog])
            ->assertCanNotSeeTableRecords([$otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('Legacy target name')
            ->assertCanNotSeeTableRecords([$targetDialog, $otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('old_target_username')
            ->assertCanNotSeeTableRecords([$targetDialog, $otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('77317')
            ->assertCanNotSeeTableRecords([$targetDialog, $otherDialog]);

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->searchTable('Герман 177918')
            ->assertCanNotSeeTableRecords([$targetDialog, $otherDialog]);
    }

    public function test_dialogs_inbox_queries_messages_by_dialog_id_not_contact_id(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ListDialogs::class)
            ->assertCanSeeTableRecords([$dialog]);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"messages"')
                || str_contains($query, 'from messages'));

        $this->assertTrue($queries->isNotEmpty());
        $this->assertFalse($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."contact_id"')
                || str_contains($query, 'messages.contact_id')
        ));
        $this->assertTrue($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."dialog_id"')
                || str_contains($query, '"dialog_id" = "dialogs"."id"')
                || str_contains($query, 'messages.dialog_id = dialogs.id')
                || str_contains($query, 'latest_inbound_user.dialog_id = dialogs.id')
                || str_contains($query, 'newer_inbound_user.dialog_id = dialogs.id')
                || str_contains($query, 'later_outbound_manual_reply.dialog_id = dialogs.id')
        ));
    }

    public function test_dialog_view_shows_only_messages_from_this_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман',
        ]);
        $telegram = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
        ]);
        $max = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
        ]);

        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'platform' => $telegram->platform,
            'external_user_id' => 'tg-100',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'platform' => $max->platform,
            'external_user_id' => 'max-100',
        ]);

        $targetDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegram->id,
            'current_contact_identity_id' => $telegramIdentity->id,
        ]);
        $otherDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $max->id,
            'current_contact_identity_id' => $maxIdentity->id,
        ]);

        Message::factory()->create([
            'dialog_id' => $targetDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegram->id,
            'text' => 'Сообщение Telegram',
            'received_at' => now()->subMinute(),
        ]);
        Message::factory()->create([
            'dialog_id' => $otherDialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $max->id,
            'text' => 'Сообщение MAX',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $targetDialog->getRouteKey()])
            ->assertSee('Сообщение Telegram')
            ->assertDontSee('Сообщение MAX');
    }

    public function test_dialog_view_initially_loads_latest_twenty_five_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $messages = $component->get('conversationMessages');

        $this->assertCount(ViewDialog::INITIAL_CONVERSATION_MESSAGE_LIMIT, $messages);
        $this->assertSame('Сообщение 46', $messages[0]['display_text']);
        $this->assertSame('Сообщение 70', $messages[24]['display_text']);
        $component->assertSet('hasMoreOlderMessages', true)
            ->assertSee('Показать более ранние');
    }

    public function test_dialog_message_page_loader_skips_dialog_eager_load_for_feed_rows(): void
    {
        $dialog = $this->createDialogWithMessages(3);

        $page = app(LoadDialogMessagesPageAction::class)->handle($dialog);

        $this->assertCount(3, $page->messages);
        $this->assertTrue($page->messages->every(
            fn (Message $message): bool => $message->relationLoaded('channel')
        ));
        $this->assertFalse($page->messages->contains(
            fn (Message $message): bool => $message->relationLoaded('dialog')
        ));
    }

    public function test_dialog_view_live_refresh_appends_new_messages_without_losing_local_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $newOwner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Новый ответственный',
        ]);
        $dialog = $this->createDialogWithMessages(3);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyText', 'Черновик без потери')
            ->set('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->set('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML);

        $dialog->contact->update([
            'assigned_user_id' => $newOwner->id,
        ]);

        $newInboundMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Новое входящее без перезагрузки',
            'received_at' => now()->addSecond(),
            'external_message_id' => 'live-refresh-001',
            'provider_event_key' => 'live-refresh-event-001',
        ]);

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed')
            ->assertSet('dialogReplyText', 'Черновик без потери')
            ->assertSet('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->assertSet('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML)
            ->assertSee('Новое входящее без перезагрузки')
            ->assertSee('Новый ответственный');

        $messages = $component->get('conversationMessages');

        $this->assertCount(4, $messages);
        $this->assertSame('Новое входящее без перезагрузки', $messages[3]['display_text']);
        $this->assertSame($newInboundMessage->id, $component->get('latestKnownMessageId'));
    }

    public function test_dialog_view_live_refresh_updates_existing_visible_media_state(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);
        $message = Message::query()
            ->where('dialog_id', $dialog->id)
            ->firstOrFail();
        $message->forceFill([
            'text' => null,
            'message_kind' => Message::KIND_INBOUND_USER,
            'provider_event_key' => 'live-refresh-video-note-event-1',
            'external_message_id' => 'live-refresh-video-note-message-1',
        ])->save();
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'video-note:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_VIDEO_NOTE,
            'original_filename' => 'round.mp4',
            'mime_type' => 'video/mp4',
            'extension' => 'mp4',
            'file_size_bytes' => 4096,
            'provider_metadata' => [
                'duration' => 6,
                'is_video_note' => true,
            ],
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $messages = $component->get('conversationMessages');

        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING, $messages[0]['media_items'][0]['status']);
        $this->assertFalse($messages[0]['media_items'][0]['is_previewable']);
        $this->assertSame('Загружается', $messages[0]['media_items'][0]['status_label']);

        $attachment->forceFill([
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
            'local_disk' => MessageAttachment::LOCAL_DISK_PRIVATE,
            'local_path' => MessageAttachment::LOCAL_PATH_PREFIX.'/'.$message->id.'/round.mp4',
        ])->save();

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed');

        $messages = $component->get('conversationMessages');

        $this->assertCount(1, $messages);
        $this->assertSame('Кружок', $messages[0]['display_text']);
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, $messages[0]['media_items'][0]['status']);
        $this->assertTrue($messages[0]['media_items'][0]['is_video_note']);
        $this->assertTrue($messages[0]['media_items'][0]['is_previewable']);
        $this->assertSame(MessageAttachment::PREVIEW_KIND_VIDEO, $messages[0]['media_items'][0]['preview_kind']);
        $this->assertSame(
            route('admin.message-attachments.preview', ['attachment' => $attachment->id]),
            $messages[0]['media_items'][0]['preview_url'],
        );
    }

    public function test_dialog_view_live_refresh_does_not_duplicate_already_loaded_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Сообщение только один раз',
            'received_at' => now()->addSecond(),
            'external_message_id' => 'live-refresh-002',
            'provider_event_key' => 'live-refresh-event-002',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('refreshDialogViewData')
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed');

        $messages = $component->get('conversationMessages');

        $this->assertCount(2, $messages);
        $this->assertSame([
            'Сообщение 1',
            'Сообщение только один раз',
        ], array_column($messages, 'display_text'));
    }

    public function test_dialog_view_live_refresh_merges_new_album_sibling_into_existing_conversation_item(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(1);
        $firstMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->firstOrFail();
        $firstMessage->update([
            'text' => null,
            'provider_group_key' => 'live-refresh-album',
            'provider_event_key' => 'live-refresh-album-event-1',
            'external_message_id' => 'live-refresh-album-message-1',
        ]);
        MessageAttachment::factory()->create([
            'message_id' => $firstMessage->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $firstMessage->provider_event_key,
            'provider_attachment_key' => '0:image:file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'album-first.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $this->assertCount(1, $component->get('conversationMessages'));

        $secondMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Подпись альбома',
            'received_at' => $firstMessage->received_at?->copy()->addSecond() ?? now()->addSecond(),
            'external_message_id' => 'live-refresh-album-message-2',
            'provider_event_key' => 'live-refresh-album-event-2',
            'provider_group_key' => 'live-refresh-album',
        ]);
        MessageAttachment::factory()->create([
            'message_id' => $secondMessage->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_MAX_BOT,
            'provider_event_key' => $secondMessage->provider_event_key,
            'provider_attachment_key' => '1:image:file-2',
            'media_kind' => MessageAttachment::MEDIA_KIND_IMAGE,
            'original_filename' => 'album-second.jpg',
            'mime_type' => 'image/jpeg',
            'extension' => 'jpg',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
        ]);

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed')
            ->assertSee('Подпись альбома');

        $messages = $component->get('conversationMessages');

        $this->assertCount(1, $messages);
        $this->assertSame([$firstMessage->id, $secondMessage->id], $messages[0]['message_ids']);
        $this->assertTrue($messages[0]['is_grouped']);
        $this->assertSame('Подпись альбома', $messages[0]['display_text']);
        $this->assertCount(2, $messages[0]['media_items']);
        $this->assertSame($secondMessage->id, $component->get('latestKnownMessageId'));
    }

    public function test_dialog_view_live_refresh_inserts_late_arriving_message_into_chronological_position(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);
        $secondMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('text', 'Сообщение 2')
            ->firstOrFail();

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Поздно дошедшее сообщение',
            'received_at' => $secondMessage->received_at,
            'external_message_id' => 'live-refresh-late-001',
            'provider_event_key' => 'live-refresh-late-event-001',
        ]);

        $component
            ->call('refreshDialogViewData')
            ->assertDispatched('dialog-history-refreshed');

        $messages = $component->get('conversationMessages');

        $this->assertCount(4, $messages);
        $this->assertSame([
            'Сообщение 1',
            'Сообщение 2',
            'Поздно дошедшее сообщение',
            'Сообщение 3',
        ], array_column($messages, 'display_text'));
    }

    public function test_dialog_view_load_older_messages_does_not_duplicate_late_message_inserted_by_live_refresh(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);
        $firstVisibleMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('text', 'Сообщение 46')
            ->firstOrFail();
        $twentiethMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('text', 'Сообщение 20')
            ->firstOrFail();

        $lateMessage = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Поздно дошедшее сообщение',
            'received_at' => $twentiethMessage->received_at,
            'external_message_id' => 'live-refresh-late-older-001',
            'provider_event_key' => 'live-refresh-late-older-event-001',
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('refreshDialogViewData')
            ->assertSet('nextOlderCursor.id', $firstVisibleMessage->id)
            ->call('loadOlderMessages')
            ->assertDispatched('dialog-history-older-messages-loaded');

        $messages = $component->get('conversationMessages');
        $messageIds = array_column($messages, 'id');
        $messageTexts = array_column($messages, 'display_text');

        $this->assertCount(71, $messages);
        $this->assertCount(71, array_unique($messageIds));
        $this->assertSame([
            'Сообщение 20',
            'Поздно дошедшее сообщение',
            'Сообщение 21',
        ], array_slice($messageTexts, 19, 3));
        $this->assertSame('Сообщение 1', $messageTexts[0]);
        $this->assertSame('Сообщение 70', $messageTexts[70]);
    }

    public function test_dialog_view_can_load_older_messages(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('loadOlderMessages')
            ->assertDispatched('dialog-history-older-messages-loaded');

        $messages = $component->get('conversationMessages');

        $this->assertCount(70, $messages);
        $this->assertSame('Сообщение 1', $messages[0]['display_text']);
        $this->assertSame('Сообщение 70', $messages[69]['display_text']);
    }

    public function test_dialog_view_hides_load_older_button_when_history_is_exhausted(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(20);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('hasMoreOlderMessages', false)
            ->assertDontSee('Загрузить более ранние сообщения');
    }

    public function test_dialog_view_uses_existing_conversation_renderer_labels_and_fallbacks(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_AUTO_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_AUTO_REPLY,
            'text' => null,
            'received_at' => now(),
        ]);
        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'text' => null,
            'received_at' => now()->addSecond(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Автоответчик')
            ->assertSee('Автоответ')
            ->assertSee('Поделился контактом')
            ->assertDontSee('data-role="conversation-channel"', false);
    }

    public function test_dialog_view_shows_shared_contact_phone_number_when_available(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'contact' => [
                        'phone_number' => '+7 999 123 45 67',
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $dialog->forceFill([
            'last_message_id' => $message->id,
            'last_message_at' => $message->received_at,
            'last_message_preview' => 'Поделился номером телефона',
            'last_inbound_message_id' => $message->id,
            'last_inbound_at' => $message->received_at,
            'last_inbound_message_preview' => 'Поделился номером телефона',
        ])->save();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-contact-share"', false)
            ->assertSee('Поделился номером')
            ->assertSee('+7 999 123 45 67')
            ->assertDontSee('Поделился контактом');
    }

    public function test_dialog_view_shows_telegram_forwarded_username_when_available(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;
        $channel?->forceFill([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ])->save();

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'telegram-forwarded-username-message',
            'provider_event_key' => 'telegram-forwarded-username-event',
            'text' => 'Пересланный текст',
            'raw_payload' => [
                'message' => [
                    'message_id' => 52,
                    'text' => 'Пересланный текст',
                    'forward_origin' => [
                        'type' => 'user',
                        'sender_user' => [
                            'id' => 5359196982,
                            'first_name' => 'Служба поддержки lava.top',
                            'username' => 'lava_support',
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-forwarded"', false)
            ->assertSee('Переслано от Служба поддержки lava.top')
            ->assertSee('Telegram user_id')
            ->assertSee('5359196982')
            ->assertSee('Telegram username')
            ->assertSee('@lava_support');
    }

    public function test_dialog_view_renders_max_contact_share_card_without_phone(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;
        $channel?->forceFill([
            'platform' => Channel::PLATFORM_MAX,
        ])->save();

        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'text' => null,
            'raw_payload' => [
                'message' => [
                    'body' => [
                        'attachments' => [
                            [
                                'type' => 'contact',
                                'payload' => [
                                    'max_info' => [
                                        'name' => 'Александр Бабичев',
                                        'user_id' => 106381897,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'received_at' => now(),
        ]);

        $dialog->forceFill([
            'last_message_id' => $message->id,
            'last_message_at' => $message->received_at,
            'last_message_preview' => 'Поделился контактом',
            'last_inbound_message_id' => $message->id,
            'last_inbound_at' => $message->received_at,
            'last_inbound_message_preview' => 'Поделился контактом',
        ])->save();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('data-role="conversation-contact-share"', false)
            ->assertSee('Поделился контактом')
            ->assertSee('Александр Бабичев')
            ->assertSee('MAX user_id')
            ->assertSee('106381897')
            ->assertSee('AB контакт')
            ->assertSee('не найден')
            ->assertDontSee('Поделился номером телефона');
    }

    public function test_dialog_view_shows_bitrix24_sender_label_for_bitrix24_openlines_message(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES,
            'provider_event_key' => 'bitrix24-openlines:view-1',
            'text' => 'Сообщение из Bitrix24',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Bitrix24')
            ->assertSee('Сообщение из Bitrix24')
            ->assertDontSee('Оператор:');
    }

    public function test_dialog_view_shows_max_bot_started_payload_as_human_readable_system_event(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(0);
        $contact = $dialog->contact;
        $identity = $dialog->currentContactIdentity;
        $channel = $dialog->channel;
        $payload = str_repeat('TEXT_1-', 25);
        $expectedDisplayText = 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity?->id,
            'channel_id' => $channel?->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => null,
            'raw_payload' => [
                'update_type' => 'bot_started',
                'payload' => $payload,
            ],
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee($expectedDisplayText)
            ->assertDontSee('Системное сообщение');
    }

    public function test_dialog_view_shows_telegram_start_payload_as_human_readable_deep_link_event(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Продакшен',
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '8010492155',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '8010492155',
        ]);
        $payload = str_repeat('TEXT_1-', 25);
        $expectedDisplayText = 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start '.$payload,
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee($expectedDisplayText)
            ->assertDontSee('/start '.$payload);
    }

    public function test_dialog_view_keeps_plain_telegram_start_command_as_raw_text(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Продакшен',
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '8010492155',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '8010492155',
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => '/start',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('/start')
            ->assertDontSee('Открыл бота по диплинку');
    }

    public function test_dialog_view_queries_messages_by_dialog_id_not_contact_id(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()]);

        $queries = collect(DB::getQueryLog())
            ->pluck('query')
            ->filter(fn (string $query): bool => str_contains($query, '"messages"'));

        $this->assertTrue($queries->isNotEmpty());
        $this->assertFalse($queries->contains(
            fn (string $query): bool => str_contains($query, '"messages"."contact_id"')
        ));
        $this->assertTrue($queries->contains(
            fn (string $query): bool => str_contains($query, '"dialog_id"')
        ));
    }

    public function test_dialog_view_contact_link_points_to_contact_page_url(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $contact = $dialog->contact;

        $response = $this->actingAs($admin)
            ->get(DialogResource::getUrl('view', ['record' => $dialog]));

        $response->assertOk()
            ->assertSee(ContactResource::getUrl('view', ['record' => $contact]), escape: false);
    }

    public function test_dialog_view_shows_reply_composer(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('Написать клиенту')
            ->assertSee('Отправить')
            ->assertSee('rows="1"', false)
            ->assertSee('conversation-reply-textarea-height', false)
            ->assertSee('reply-textarea-manual-resized', false)
            ->assertSee('onkeydown="if (event.key === \'Enter\'', false)
            ->assertSee('querySelector(\'[data-role=conversation-reply-submit]\').click()', false);
    }

    public function test_dialog_view_can_send_reply_and_append_message_without_losing_loaded_history(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-dialog-reply-001',
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(70);
        $dialog->contact->update([
            'assigned_user_id' => null,
        ]);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->call('loadOlderMessages')
            ->set('dialogReplyText', '  Ответ из dialog page  ')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSet('dialogReplyText', '')
            ->assertSee('Ответ из dialog page');

        $messages = $component->get('conversationMessages');

        $this->assertCount(71, $messages);
        $this->assertSame('Сообщение 1', $messages[0]['display_text']);
        $this->assertSame('Ответ из dialog page', $messages[70]['display_text']);

        $dialog->contact->refresh();

        $this->assertNull($dialog->contact->assigned_user_id);

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=66552012')
            && $request['text'] === 'Ответ из dialog page');
    }

    public function test_dialog_view_can_toggle_between_formatted_and_html_source_modes_for_html_reply(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-dialog-html-001',
                ],
            ]),
        ]);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages(3);

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyFormat', Message::TEXT_FORMAT_HTML)
            ->set('dialogReplyText', '<b>HTML ответ</b>')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSeeHtml('<b>HTML ответ</b>')
            ->assertDontSeeHtml('&lt;b&gt;HTML ответ&lt;/b&gt;');

        $messages = $component->get('conversationMessages');

        $this->assertSame(Message::TEXT_FORMAT_HTML, $messages[3]['text_format']);
        $this->assertSame('HTML ответ', $messages[3]['display_text']);
        $this->assertSame('<b>HTML ответ</b>', $messages[3]['formatted_html']);
        $this->assertSame('<b>HTML ответ</b>', $messages[3]['html_source_text']);

        $component
            ->set('conversationDisplayMode', ViewDialog::CONVERSATION_DISPLAY_MODE_HTML)
            ->assertSeeHtml('&lt;b&gt;HTML ответ&lt;/b&gt;')
            ->assertDontSeeHtml('<b>HTML ответ</b>');

        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://platform-api.max.ru/messages?')
            && str_contains($request->url(), 'chat_id=66552012')
            && $request['text'] === '<b>HTML ответ</b>'
            && $request['format'] === 'html');
    }

    public function test_dialog_view_can_mark_dialog_as_not_required_and_write_history_note(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Оператор Статуса',
        ]);
        $dialog = $this->createInboxDialog();
        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->call('setDialogInboxStatus', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->assertNotified()
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_NOT_REQUIRED)
            ->assertSee('Не требует ответа')
            ->assertSee('Оператор Оператор Статуса изменил статус диалога: Требует ответа -> Не требует ответа');

        $this->assertSame(
            $latestInbound->id,
            $dialog->fresh()->manual_reply_dismissed_source_message_id,
        );

        $this->assertDatabaseHas('messages', [
            'dialog_id' => $dialog->id,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_user_id' => $admin->id,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
            'reply_to_message_id' => $latestInbound->id,
            'text' => 'Оператор Оператор Статуса изменил статус диалога: Требует ответа -> Не требует ответа',
        ]);

        $historyMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE)
            ->latest('id')
            ->firstOrFail();

        $this->assertEquals(
            [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_INBOX_STATUS_CHANGE,
                'from_status' => [
                    'code' => DialogInboxStatusData::CODE_REQUIRES_REPLY,
                    'label' => 'Требует ответа',
                ],
                'to_status' => [
                    'code' => DialogInboxStatusData::CODE_NOT_REQUIRED,
                    'label' => 'Не требует ответа',
                ],
                'reply_to_message_id' => $latestInbound->id,
                'dialog_id' => $dialog->id,
                'changed_by_user_id' => $admin->id,
            ],
            $historyMessage->raw_payload,
        );

        $this->assertNotNull($historyMessage->received_at);
    }

    public function test_dialog_view_live_refresh_returns_manually_dismissed_dialog_to_requires_reply_after_new_inbound(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createInboxDialog();
        $latestInbound = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->latest('id')
            ->firstOrFail();

        $dialog->forceFill([
            'manual_reply_dismissed_source_message_id' => $latestInbound->id,
        ])->save();

        $component = Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_NOT_REQUIRED);

        $receivedAt = now()->addSecond();

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Новое сообщение после ручного закрытия',
            'received_at' => $receivedAt,
            'external_message_id' => 'dialog-status-refresh-001',
            'provider_event_key' => 'dialog-status-refresh-event-001',
        ]);

        $dialog->forceFill([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
        ])->save();

        $component
            ->call('refreshDialogViewData')
            ->assertSet('dialogInboxStatusSelection', DialogInboxStatusData::CODE_REQUIRES_REPLY)
            ->assertSee('Новое сообщение после ручного закрытия');
    }

    public function test_employee_can_send_reply_from_dialog_page_without_reassigning_foreign_contact(): void
    {
        Http::fake([
            'https://platform-api.max.ru/messages*' => Http::response([
                'message' => [
                    'message_id' => 'max-employee-reply-001',
                ],
            ]),
        ]);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => $owner->id,
        ]);

        $initialMessagesCount = Message::query()->count();
        Livewire::actingAs($employee)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->set('dialogReplyText', 'Employee reply attempt')
            ->call('sendDialogReply')
            ->assertNotified()
            ->assertDispatched('dialog-reply-sent')
            ->assertSee('Employee reply attempt');

        $this->assertSame($initialMessagesCount + 1, Message::query()->count());

        $dialog->contact->refresh();

        $this->assertSame($owner->id, $dialog->contact->assigned_user_id);
    }

    public function test_dialog_view_does_not_show_auto_claim_hint_for_unassigned_contact(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => null,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertDontSee('контакт закрепится за вами автоматически');
    }

    public function test_dialog_view_does_not_block_reply_for_foreign_assignee(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'name' => 'Другой сотрудник',
        ]);
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $dialog = $this->createDialogWithMessages();
        $dialog->contact->update([
            'assigned_user_id' => $owner->id,
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertDontSee('Контакт уже назначен сотруднику Другой сотрудник.')
            ->assertSee('data-role="conversation-reply-form"', false);
    }

    public function test_dialog_view_disables_reply_for_unsendable_exact_dialog(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $channel = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
        ]);
        $contact = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => null,
        ]);

        Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => '',
            'external_message_id' => 'telegram-unsendable',
            'received_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(ViewDialog::class, ['record' => $dialog->getRouteKey()])
            ->assertSee('У этого диалога сейчас нет рабочего маршрута для отправки ответа.');
    }

    protected function createDialogWithMessages(int $messagesCount = 1): Dialog
    {
        $channel = Channel::factory()->create([
            'name' => 'MAX-Лесли',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => [
                'token' => 'max-token',
            ],
            'is_active' => true,
        ]);
        $contact = Contact::factory()->create([
            'name' => 'Герман Абрикосов',
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => '228532008',
            'external_username' => 'german_a',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '66552012',
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 926 352 71 11',
            'phone_normalized' => '79263527111',
            'source' => ContactPhoneNumber::SOURCE_MAX_CONTACT_SHARE,
            'is_primary' => true,
        ]);

        for ($index = 1; $index <= $messagesCount; $index++) {
            Message::factory()->create([
                'dialog_id' => $dialog->id,
                'contact_id' => $contact->id,
                'contact_identity_id' => $identity->id,
                'channel_id' => $channel->id,
                'text' => sprintf('Сообщение %d', $index),
                'received_at' => now()->subSeconds($messagesCount - $index),
                'external_message_id' => sprintf('msg-%d', $index),
                'provider_event_key' => sprintf('event-%d', $index),
            ]);
        }

        return $dialog->fresh(['contact.assignedUser', 'contact.phoneNumbers', 'contact.primaryIdentity', 'channel', 'currentContactIdentity']);
    }

    protected function createPublishedScenarioVersion(array $schemaPayload): ScenarioVersion
    {
        $scenario = Scenario::query()->create([
            'code' => 'dialog_card_test_'.Str::random(8),
            'name' => 'Dialog card test',
            'is_active' => true,
            'is_archived' => false,
        ]);

        return ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => $schemaPayload,
        ])->fresh(['scenario']);
    }

    /**
     * @return array{Dialog, Dialog}
     */
    protected function createMultiChannelDialogsForContactLabel(): array
    {
        $contact = Contact::factory()->create([
            'name' => null,
            'first_name' => null,
            'last_name' => null,
        ]);
        $telegramChannel = Channel::factory()->create([
            ...$this->connectedTelegramChannelAttributes('telegram-token'),
            'name' => 'Telegram Support',
        ]);
        $maxChannel = Channel::factory()->create([
            'name' => 'MAX Support',
            'platform' => Channel::PLATFORM_MAX,
            'credentials' => ['token' => 'max-token'],
            'is_active' => true,
        ]);
        $telegramIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'platform' => $telegramChannel->platform,
            'external_user_id' => 'telegram-contact-label',
            'display_name' => 'Telegram Клиент',
        ]);
        $maxIdentity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'platform' => $maxChannel->platform,
            'external_user_id' => 'max-contact-label',
            'display_name' => 'MAX Клиент',
        ]);

        $telegramDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $telegramChannel->id,
            'current_contact_identity_id' => $telegramIdentity->id,
            'external_chat_id' => 'telegram-contact-label-chat',
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);
        $maxDialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $maxChannel->id,
            'current_contact_identity_id' => $maxIdentity->id,
            'external_chat_id' => 'max-contact-label-chat',
            'last_message_at' => now(),
            'last_inbound_at' => now(),
        ]);

        $this->createDialogMessage($telegramDialog, [
            'contact_identity_id' => $telegramIdentity->id,
            'channel_id' => $telegramChannel->id,
            'text' => 'Телеграм диалог',
            'received_at' => now()->subMinute(),
        ]);
        $this->createDialogMessage($maxDialog, [
            'contact_identity_id' => $maxIdentity->id,
            'channel_id' => $maxChannel->id,
            'text' => 'MAX диалог',
            'received_at' => now(),
        ]);

        return [
            $telegramDialog->fresh(['channel', 'currentContactIdentity', 'contact.assignedUser', 'contact.identities']),
            $maxDialog->fresh(['channel', 'currentContactIdentity', 'contact.assignedUser', 'contact.identities']),
        ];
    }

    /**
     * @param  array{
     *     contactName?:string,
     *     assignedUserId?:?int,
     *     channelName?:string,
     *     platform?:string,
     *     externalUserId?:string,
     *     externalUsername?:?string,
     *     displayName?:?string,
     *     externalChatId?:?string,
     *     hasToken?:bool
     * }  $attributes
     */
    protected function createInboxDialog(array $attributes = []): Dialog
    {
        $platform = $attributes['platform'] ?? Channel::PLATFORM_MAX;
        $hasToken = $attributes['hasToken'] ?? true;
        $channelAttributes = [
            'name' => $attributes['channelName'] ?? ($platform === Channel::PLATFORM_TELEGRAM ? 'Telegram Support' : 'MAX Support'),
            'platform' => $platform,
            'credentials' => $hasToken ? ['token' => $platform.'-token'] : [],
            'is_active' => true,
        ];

        if ($platform === Channel::PLATFORM_TELEGRAM && $hasToken) {
            $channelAttributes = array_merge(
                $this->connectedTelegramChannelAttributes($platform.'-token'),
                $channelAttributes,
            );
        }

        $channel = Channel::factory()->create($channelAttributes);
        $contact = Contact::factory()->create([
            'name' => $attributes['contactName'] ?? 'Inbox contact',
            'first_name' => $attributes['contactFirstName'] ?? null,
            'last_name' => $attributes['contactLastName'] ?? null,
            'assigned_user_id' => $attributes['assignedUserId'] ?? null,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => $attributes['externalUserId'] ?? 'external-user-'.fake()->unique()->numerify('###'),
            'display_name' => $attributes['displayName'] ?? null,
            'external_username' => $attributes['externalUsername'] ?? null,
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => array_key_exists('externalChatId', $attributes)
                ? $attributes['externalChatId']
                : 'chat-'.fake()->unique()->numerify('###'),
            'last_message_at' => now()->subMinute(),
            'last_inbound_at' => now()->subMinute(),
        ]);

        $this->createDialogMessage($dialog, [
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'message_kind' => Message::KIND_INBOUND_USER,
            'text' => 'Пользователь написал первым',
            'received_at' => now()->subMinute(),
        ]);

        return $dialog->fresh([
            'channel',
            'currentContactIdentity',
            'contact.assignedUser',
            'contact.primaryIdentity',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function createDialogMessage(Dialog $dialog, array $attributes = []): Message
    {
        $receivedAt = $attributes['received_at'] ?? now();
        $message = Message::factory()->create(array_merge([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'external_chat_id' => $dialog->external_chat_id ?? '',
            'external_message_id' => 'msg-'.fake()->unique()->numerify('###'),
            'provider_event_key' => 'event-'.fake()->unique()->numerify('###'),
            'text' => 'Inbox message',
            'received_at' => $receivedAt,
        ], $attributes));

        $dialog->forceFill([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $message->direction === Message::DIRECTION_INBOUND ? $receivedAt : $dialog->last_inbound_at,
            'last_outbound_at' => $message->direction === Message::DIRECTION_OUTBOUND ? $receivedAt : $dialog->last_outbound_at,
            ...app(BuildDialogMessageSnapshotPayloadAction::class)->fromMessages(
                Message::query()
                    ->where('dialog_id', $dialog->id)
                    ->get(),
            ),
        ])->save();

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    protected function connectedTelegramChannelAttributes(string $token): array
    {
        return [
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'credentials' => ['token' => $token],
            'is_active' => true,
            'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
            'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
            'connection_checked_at' => now(),
            'connection_error_message' => null,
            'provider_webhook_url' => null,
            'expected_webhook_url' => null,
        ];
    }
}
