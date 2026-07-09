<?php

namespace App\Filament\Resources\Dialogs\Pages;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\ChannelPeerSyncState;
use App\Models\Contact;
use App\Models\ContactPhoneNumber;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Bots\ContactIdentityAvatarStorage;
use App\Services\Bots\SendManualDialogReplyAction;
use App\Services\CardViews\CardViewFieldRendererRegistry;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Contacts\SetContactAssigneeAction;
use App\Services\Colors\ColorRegistry;
use App\Services\Dialogs\BuildConversationFeedViewDataAction;
use App\Services\Dialogs\BuildDialogCardViewLayoutAction;
use App\Services\Dialogs\DialogCardViewBlockRegistry;
use App\Services\Dialogs\DialogStageCatalog;
use App\Services\Dialogs\LoadDialogMessagesPageAction;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use App\Services\Dialogs\ResolveDialogStageAction;
use App\Services\Dialogs\SyncSystemDialogCardViewAction;
use App\Services\Dialogs\UpdateDialogInboxStatusAction;
use App\Services\Dialogs\UpdateDialogStageAction;
use App\Services\Scenarios\ScenarioEdgeExpressionCondition;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

class ViewDialog extends ViewRecord
{
    public const CONVERSATION_DISPLAY_MODE_FORMATTED = 'formatted';

    public const CONVERSATION_DISPLAY_MODE_HTML = 'html';

    public const INITIAL_CONVERSATION_MESSAGE_LIMIT = 25;

    public const OLDER_CONVERSATION_MESSAGE_LIMIT = 50;

    public const LIVE_REFRESH_MESSAGE_LIMIT = 50;

    public const LIVE_REFRESH_INTERVAL_MS = 5000;

    private const DIALOG_FIELD_VALUE_MAX_LENGTH = 2000;

    private const DIALOG_FIELDS_MAX_BYTES = 65536;

    protected static string $resource = DialogResource::class;

    protected string $view = 'filament.dialogs.pages.view-dialog';

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @var list<array<string, mixed>>
     */
    public array $conversationMessages = [];

    public bool $hasMoreOlderMessages = false;

    public string $dialogReplyText = '';

    public string $dialogReplyFormat = Message::TEXT_FORMAT_PLAIN_TEXT;

    public string $dialogInboxStatusSelection = DialogInboxStatusData::CODE_NO_NEW;

    public string $dialogStageSelection = '';

    public bool $isDialogAssigneeEditing = false;

    public string $selectedDialogAssigneeId = '';

    public string $conversationDisplayMode = self::CONVERSATION_DISPLAY_MODE_FORMATTED;

    /**
     * @var array{sort_at:string,id:int}|null
     */
    public ?array $nextOlderCursor = null;

    public ?int $latestKnownMessageId = null;

    public ?string $dialogsBackUrl = null;

    #[Url(as: 'tab', history: true, except: SyncSystemDialogCardViewAction::TAB_GENERAL)]
    public string $activeTab = SyncSystemDialogCardViewAction::TAB_GENERAL;

    /**
     * @var array<string, string>|null
     */
    protected ?array $dialogFieldLabels = null;

    /**
     * @var array<string, true>|null
     */
    protected ?array $editableDialogFieldKeys = null;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $dialogOptionLabels = [];

    /**
     * @var array<string, ?string>
     */
    protected array $dialogAvatarUrlCache = [];

    /**
     * @var array<string, string>
     */
    public array $dialogFieldDraftValues = [];

    public bool $dialogFieldDraftDirty = false;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->dialogsBackUrl = $this->resolveDialogsBackUrlFromValue(request()->query('back_to'));
        $this->activeTab = $this->normalizeCardTab((string) request()->query('tab', SyncSystemDialogCardViewAction::TAB_GENERAL));

        $this->initializeConversationHistory();
        $this->fillDialogFieldDraftValues();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Диалог';
    }

    public function getHeading(): string|Htmlable|null
    {
        return null;
    }

    public function getSubheading(): ?string
    {
        return null;
    }

    public function hasResourceBreadcrumbs(): bool
    {
        return false;
    }

    public function getBreadcrumb(): string
    {
        return 'Диалог: '.$this->formatChannelLabel($this->getRecord()->channel, 'Неизвестный канал');
    }

    public function getBreadcrumbs(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            ContactResource::getUrl('index') => ContactResource::getBreadcrumb(),
            $this->getContactViewUrl() => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт',
            $this->getBreadcrumb(),
        ];
    }

    public function loadOlderMessages(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $page = app(LoadDialogMessagesPageAction::class)->handle(
            $this->getRecord(),
            $this->nextOlderCursor,
            self::OLDER_CONVERSATION_MESSAGE_LIMIT,
        );

        $olderMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);

        if ($olderMessages === []) {
            $this->hasMoreOlderMessages = false;
            $this->nextOlderCursor = null;

            return;
        }

        $this->prependConversationMessageViewData($olderMessages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->syncNextOlderCursorToVisibleConversationStart();

        $this->dispatch('dialog-history-older-messages-loaded');
    }

    public function refreshDialogViewData(): void
    {
        $this->refreshDialogRecord();
        $this->syncDialogInboxStatusSelection();
        $this->syncDialogStageSelection();

        $refreshResult = $this->appendLatestConversationMessages();

        $this->dispatch(
            'dialog-history-refreshed',
            appendedCount: $refreshResult['appended_count'],
            updatedCount: $refreshResult['updated_count'],
        );
    }

    public function updateDialogInboxStatus(): void
    {
        try {
            $employee = $this->resolveCurrentEmployee();

            $result = app(UpdateDialogInboxStatusAction::class)->handle(
                $this->getRecord(),
                $employee,
                $this->dialogInboxStatusSelection,
            );

            if ($result->historyMessage instanceof Message) {
                $result->historyMessage->loadMissing(['channel', 'dialog.channel', 'sentByUser']);
                $this->appendOutboundMessageToConversation($result->historyMessage);
            }

            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->success()
                ->title('Статус обновлён')
                ->body('Статус диалога сохранён и добавлен в историю.')
                ->send();
        } catch (ValidationException $exception) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            $this->syncDialogInboxStatusSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить статус')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function setDialogInboxStatus(string $status): void
    {
        if ($this->dialogInboxStatusSelection === $status) {
            return;
        }

        $this->dialogInboxStatusSelection = $status;
        $this->updateDialogInboxStatus();
    }

    public function updatedActiveTab(string $value): void
    {
        $this->activeTab = $this->normalizeCardTab($value);
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = $this->normalizeCardTab($tab);
    }

    public function openDialogAssigneeEditor(): void
    {
        if (! $this->canCurrentUserManageDialogContactOwnership()) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть выбор ответственного')
                ->body('Недостаточно прав для изменения ответственного.')
                ->send();

            return;
        }

        $contact = $this->resolveReplyOwnerContact();

        if (! $contact instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть выбор ответственного')
                ->body('У диалога нет связанного контакта.')
                ->send();

            return;
        }

        $this->selectedDialogAssigneeId = filled($contact->assigned_user_id)
            ? (string) $contact->assigned_user_id
            : '';
        $this->isDialogAssigneeEditing = true;
    }

    public function closeDialogAssigneeEditor(): void
    {
        $this->isDialogAssigneeEditing = false;
        $this->selectedDialogAssigneeId = '';
    }

    public function saveDialogAssignee(): void
    {
        if (! $this->canCurrentUserManageDialogContactOwnership()) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить ответственного')
                ->body('Недостаточно прав для изменения ответственного.')
                ->send();

            return;
        }

        $contact = $this->resolveReplyOwnerContact();

        if (! $contact instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить ответственного')
                ->body('У диалога нет связанного контакта.')
                ->send();

            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();
            $assigneeId = $this->selectedDialogAssigneeId !== ''
                ? (int) $this->selectedDialogAssigneeId
                : null;

            app(SetContactAssigneeAction::class)->handle($contact, $employee, $assigneeId);

            $this->isDialogAssigneeEditing = false;
            $this->selectedDialogAssigneeId = '';
            $this->refreshDialogRecord();

            Notification::make()
                ->success()
                ->title('Ответственный обновлён')
                ->body('Изменения сохранены.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить ответственного')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function updateDialogStage(): void
    {
        try {
            $employee = $this->resolveCurrentEmployee();

            $result = app(UpdateDialogStageAction::class)->handle(
                $this->getRecord(),
                $employee,
                $this->dialogStageSelection,
            );

            if ($result->historyMessage instanceof Message) {
                $result->historyMessage->loadMissing(['channel', 'dialog.channel', 'sentByUser']);
                $this->appendOutboundMessageToConversation($result->historyMessage);
            }

            $this->refreshDialogRecord();
            $this->syncDialogStageSelection();

            Notification::make()
                ->success()
                ->title('Этап обновлён')
                ->body('Этап диалога сохранён и добавлен в историю.')
                ->send();
        } catch (ValidationException $exception) {
            $this->syncDialogStageSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить этап')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            $this->syncDialogStageSelection();

            Notification::make()
                ->danger()
                ->title('Не удалось изменить этап')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function selectDialogStage(string $stage): void
    {
        $this->dialogStageSelection = $stage;

        $this->updateDialogStage();
    }

    public function sendDialogReply(): void
    {
        $validated = $this->validate([
            'dialogReplyText' => ['required', 'string', 'max:2000'],
            'dialogReplyFormat' => ['required', 'string', Rule::in(array_keys(Message::textFormatOptions()))],
        ]);

        $text = trim((string) ($validated['dialogReplyText'] ?? ''));
        $textFormat = Message::normalizeTextFormat($validated['dialogReplyFormat'] ?? null);

        if ($text === '') {
            throw ValidationException::withMessages([
                'dialogReplyText' => 'Текст ответа обязателен.',
            ]);
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            $outboundMessage = app(SendManualDialogReplyAction::class)->handle(
                $this->getRecord(),
                $employee,
                $text,
                $textFormat,
            );

            $this->dialogReplyText = '';
            $this->appendOutboundMessageToConversation($outboundMessage);
            $this->refreshDialogRecord();
            $this->syncDialogInboxStatusSelection();
            $this->dispatch('dialog-reply-sent');

            $isQueuedForGateway = data_get($outboundMessage->raw_payload, 'provider') === 'telegram_account_gateway'
                && data_get($outboundMessage->raw_payload, 'delivery_status') === 'pending';

            Notification::make()
                ->success()
                ->title($isQueuedForGateway ? 'Ответ поставлен в очередь' : 'Ответ отправлен')
                ->body($isQueuedForGateway
                    ? 'Gateway заберёт сообщение и отправит его через Telegram account.'
                    : 'Сообщение отправлено и сохранено в истории диалога.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось отправить ответ')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function saveDialogFieldValue(string $fieldKey, mixed $value): void
    {
        $fieldKey = trim($fieldKey);
        $valueText = is_scalar($value) || $value === null
            ? (string) $value
            : '';

        try {
            $this->persistDialogFieldValues([$fieldKey => $valueText]);
            $this->fillDialogFieldDraftValues();

            Notification::make()
                ->success()
                ->title('Поле обновлено')
                ->body('Значение поля диалога сохранено.')
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поле')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поле')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function saveDialogFieldDraftValues(): void
    {
        try {
            $changes = $this->changedDialogFieldDraftValues();
            $assigneeChanged = $this->hasDialogAssigneeDraftChanges();

            if ($changes === [] && ! $assigneeChanged) {
                $this->dialogFieldDraftDirty = false;

                return;
            }

            if ($changes !== []) {
                $this->persistDialogFieldValues($changes);
            }

            if ($assigneeChanged) {
                $this->persistDialogAssigneeDraftValue();
                $this->isDialogAssigneeEditing = false;
                $this->selectedDialogAssigneeId = '';
            }

            $this->fillDialogFieldDraftValues();

            Notification::make()
                ->success()
                ->title('Изменения сохранены')
                ->body('Данные диалога обновлены.')
                ->send();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поля')
                ->body((string) collect($exception->errors())->flatten()->first())
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить поля')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function resetDialogFieldDraftValues(): void
    {
        $this->fillDialogFieldDraftValues();
    }

    public function updatedDialogFieldDraftValues(): void
    {
        $this->refreshDialogDraftDirtyState();
    }

    public function updatedSelectedDialogAssigneeId(): void
    {
        $this->refreshDialogDraftDirtyState();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            'dialogHeader' => $this->getDialogHeaderViewData(),
            'peerSyncState' => $this->getPeerSyncStateViewData(),
            'contactSummary' => $this->getContactSummaryViewData(),
            'dialogFields' => $this->getDialogFieldsViewData(),
            'dialogSystemFields' => $this->getDialogSystemFieldsViewData(),
            'tabs' => $this->buildDialogCardTabs(),
            'activeTab' => $this->activeTab,
            'dialogGeneralSections' => $this->activeTab === SyncSystemDialogCardViewAction::TAB_GENERAL
                ? $this->buildDialogFieldSections(SyncSystemDialogCardViewAction::TAB_GENERAL)
                : [],
            'dialogBitrixSections' => $this->activeTab === SyncSystemDialogCardViewAction::TAB_BITRIX24
                ? $this->buildDialogFieldSections(SyncSystemDialogCardViewAction::TAB_BITRIX24)
                : [],
            'dialogSystemFieldSections' => $this->activeTab === SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS
                ? $this->buildDialogFieldSections(SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS)
                : [],
            'dialogDiagnosticsBlocks' => $this->activeTab === SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS
                ? $this->buildDialogDiagnosticsBlocks()
                : [],
            'dialogAutomationDiagnostics' => $this->activeTab === SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS
                ? $this->getDialogAutomationDiagnosticsViewData()
                : ['is_visible' => false, 'rows' => []],
            'dialogCustomSections' => $this->isKnownCardTab($this->activeTab)
                ? []
                : $this->buildDialogCustomSections($this->activeTab),
            'dialogFieldLabels' => $this->getDialogFieldLabels(),
            'dialogBreadcrumbs' => $this->getDialogBreadcrumbsViewData(),
            'kanbanBackUrl' => $this->resolveDialogsBackUrl(),
            'contactUrl' => $this->getContactViewUrl(),
            'dialogInboxStatus' => $this->getDialogInboxStatusViewData(),
            'dialogStage' => $this->getDialogStageViewData(),
            'dialogAssignee' => $this->getDialogAssigneeViewData(),
            'conversationDisplayModeOptions' => $this->getConversationDisplayModeOptions(),
            'liveRefreshPollIntervalMs' => static::LIVE_REFRESH_INTERVAL_MS,
            'replyComposer' => $this->getReplyComposerViewData(),
        ];
    }

    /**
     * @return list<array{key:string,label:string,url:string,isActive:bool}>
     */
    protected function buildDialogCardTabs(): array
    {
        try {
            $layoutTabs = app(BuildDialogCardViewLayoutAction::class)->tabs();
        } catch (Throwable $throwable) {
            Log::warning('dialog_card_view_tabs_fallback_used', [
                'dialog_id' => $this->getRecord()->id,
                'error' => $throwable->getMessage(),
            ]);

            $layoutTabs = null;
        }

        $tabs = $layoutTabs ?? [
            ['tab_key' => SyncSystemDialogCardViewAction::TAB_GENERAL, 'title' => 'Общее'],
            ['tab_key' => SyncSystemDialogCardViewAction::TAB_BITRIX24, 'title' => 'Битрикс24'],
            ['tab_key' => SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS, 'title' => 'Системные поля'],
            ['tab_key' => SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS, 'title' => 'Диагностика'],
        ];

        return collect($tabs)
            ->map(function (array $tab): array {
                $key = (string) ($tab['tab_key'] ?? '');

                return [
                    'key' => $key,
                    'label' => (string) ($tab['title'] ?? $key),
                    'url' => $this->dialogCardTabUrl($key),
                    'isActive' => $this->activeTab === $key,
                ];
            })
            ->filter(fn (array $tab): bool => $tab['key'] !== '')
            ->values()
            ->all();
    }

    protected function dialogCardTabUrl(string $tabKey): string
    {
        $params = ['record' => $this->getRecord()];

        if ($this->dialogsBackUrl !== null) {
            $params['back_to'] = $this->dialogsBackUrl;
        }

        if ($tabKey !== SyncSystemDialogCardViewAction::TAB_GENERAL) {
            $params['tab'] = $tabKey;
        }

        return DialogResource::getUrl('view', $params);
    }

    protected function normalizeCardTab(string $tab): string
    {
        $tab = trim($tab);

        return $tab !== '' ? $tab : SyncSystemDialogCardViewAction::TAB_GENERAL;
    }

    protected function isKnownCardTab(string $tab): bool
    {
        return in_array($tab, [
            SyncSystemDialogCardViewAction::TAB_GENERAL,
            SyncSystemDialogCardViewAction::TAB_BITRIX24,
            SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS,
            SyncSystemDialogCardViewAction::TAB_DIAGNOSTICS,
        ], true);
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildDialogDiagnosticsBlocks(): array
    {
        try {
            $layout = app(BuildDialogCardViewLayoutAction::class)->diagnostics();

            if (is_array($layout) && ($layout['sections'] ?? []) !== []) {
                return $layout['sections'];
            }
        } catch (Throwable $throwable) {
            Log::warning('dialog_diagnostics_card_view_fallback_used', [
                'dialog_id' => $this->getRecord()->id,
                'error' => $throwable->getMessage(),
            ]);
        }

        return $this->fallbackBlockSections(SyncSystemDialogCardViewAction::diagnosticsSections());
    }

    /**
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function buildDialogFieldSections(string $tabKey): array
    {
        try {
            $layout = match ($tabKey) {
                SyncSystemDialogCardViewAction::TAB_GENERAL => app(BuildDialogCardViewLayoutAction::class)->general(),
                SyncSystemDialogCardViewAction::TAB_BITRIX24 => app(BuildDialogCardViewLayoutAction::class)->bitrix24(),
                SyncSystemDialogCardViewAction::TAB_SYSTEM_FIELDS => app(BuildDialogCardViewLayoutAction::class)->systemFields(),
                default => null,
            };

            if (is_array($layout) && ($layout['sections'] ?? []) !== []) {
                $sections = $this->buildFieldSectionsFromLayout($layout['sections']);

                return $tabKey === SyncSystemDialogCardViewAction::TAB_GENERAL
                    ? $this->appendDialogPayloadFieldsSection($sections)
                    : $sections;
            }
        } catch (Throwable $throwable) {
            Log::warning('dialog_field_card_view_fallback_used', [
                'dialog_id' => $this->getRecord()->id,
                'tab' => $tabKey,
                'error' => $throwable->getMessage(),
            ]);
        }

        $fallback = match ($tabKey) {
            SyncSystemDialogCardViewAction::TAB_GENERAL => SyncSystemDialogCardViewAction::generalFieldSections(),
            SyncSystemDialogCardViewAction::TAB_BITRIX24 => SyncSystemDialogCardViewAction::bitrix24Sections(),
            default => SyncSystemDialogCardViewAction::systemFieldSections(),
        };

        $sections = $this->buildFieldSectionsFromDefinitions($fallback);

        return $tabKey === SyncSystemDialogCardViewAction::TAB_GENERAL
            ? $this->appendDialogPayloadFieldsSection($sections)
            : $sections;
    }

    /**
     * @param  list<array{section_key:string,title:string,rows:list<array<string, mixed>>}>  $sections
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function appendDialogPayloadFieldsSection(array $sections): array
    {
        $visibleFieldKeys = collect($sections)
            ->flatMap(fn (array $section) => $section['rows'] ?? [])
            ->map(fn (array $row): string => (string) ($row['key'] ?? ''))
            ->filter()
            ->all();

        $rows = collect($this->getDialogFieldsViewData()['fields'])
            ->reject(fn (array $field): bool => in_array((string) ($field['key'] ?? ''), $visibleFieldKeys, true))
            ->map(fn (array $field): array => $this->dialogCardRow(
                (string) ($field['key'] ?? ''),
                (string) ($field['label'] ?? ($field['key'] ?? 'Поле')),
                (string) ($field['value'] ?? '—'),
                [
                    'editable_value' => (string) ($field['editable_value'] ?? ''),
                    'can_edit' => (bool) ($field['can_edit'] ?? false),
                ],
            ))
            ->values()
            ->all();

        $sections[] = [
            'section_key' => SyncSystemDialogCardViewAction::SECTION_DIALOG_FIELDS,
            'title' => 'Поля диалога',
            'rows' => $rows,
        ];

        return $sections;
    }

    /**
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>,blocks:list<string>}>
     */
    protected function buildDialogCustomSections(string $tabKey): array
    {
        try {
            $layout = app(BuildDialogCardViewLayoutAction::class)->itemsForTab($tabKey);
        } catch (Throwable $throwable) {
            Log::warning('dialog_custom_card_view_fallback_used', [
                'dialog_id' => $this->getRecord()->id,
                'tab' => $tabKey,
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }

        if (! is_array($layout) || ($layout['sections'] ?? []) === []) {
            return [];
        }

        $rowsByKey = $this->buildDialogCardRowsByKey();
        $blockRegistry = app(DialogCardViewBlockRegistry::class);

        return collect($layout['sections'])
            ->map(function (array $section) use ($rowsByKey, $blockRegistry): array {
                $rows = [];
                $blocks = [];

                foreach (($section['items'] ?? []) as $item) {
                    $itemKey = (string) ($item['item_key'] ?? '');

                    if ($itemKey === '') {
                        continue;
                    }

                    if (($item['item_type'] ?? '') === 'field') {
                        $rendererBlockKey = (string) ($item['renderer_block_key'] ?? '');

                        if ($rendererBlockKey !== '') {
                            $blocks[] = $rendererBlockKey;

                            continue;
                        }

                        if (isset($rowsByKey[$itemKey])) {
                            $rows[] = $rowsByKey[$itemKey];
                        }

                        continue;
                    }

                    if (($item['item_type'] ?? '') === 'block' && $blockRegistry->contains($itemKey)) {
                        $blocks[] = $itemKey;
                    }
                }

                return [
                    'section_key' => (string) ($section['section_key'] ?? ''),
                    'title' => (string) ($section['title'] ?? 'Секция'),
                    'rows' => $rows,
                    'blocks' => $blocks,
                ];
            })
            ->filter(fn (array $section): bool => $section['rows'] !== [] || $section['blocks'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{section_key:string,title:string,fields:list<string>}>  $sections
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function buildFieldSectionsFromLayout(array $sections): array
    {
        $rowsByKey = $this->buildDialogCardRowsByKey();

        return collect($sections)
            ->map(fn (array $section): array => [
                'section_key' => (string) ($section['section_key'] ?? ''),
                'title' => (string) ($section['title'] ?? 'Секция'),
                'rows' => collect($section['fields'] ?? [])
                    ->map(fn (string $fieldKey): ?array => $rowsByKey[$fieldKey] ?? null)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $section): bool => $section['rows'] !== [])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, array{name:string,sort_order:int,fields:list<string>}>  $definitions
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function buildFieldSectionsFromDefinitions(array $definitions): array
    {
        return $this->buildFieldSectionsFromLayout(
            collect($definitions)
                ->map(fn (array $definition, string $sectionKey): array => [
                    'section_key' => $sectionKey,
                    'title' => $definition['name'],
                    'fields' => $definition['fields'],
                ])
                ->values()
                ->all(),
        );
    }

    /**
     * @param  array<string, array{name:string,sort_order:int,fields:list<string>}>  $definitions
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function fallbackBlockSections(array $definitions): array
    {
        $fields = FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->whereIn('field_key', collect($definitions)->flatMap(fn (array $definition): array => $definition['fields'] ?? [])->all())
            ->get()
            ->keyBy('field_key');
        $rendererRegistry = app(CardViewFieldRendererRegistry::class);

        return collect($definitions)
            ->map(function (array $definition, string $sectionKey) use ($fields, $rendererRegistry): array {
                $blocks = collect($definition['fields'] ?? [])
                    ->map(fn (string $fieldKey): string => $rendererRegistry->legacyBlockKeyForField($fields->get($fieldKey)) ?? '')
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'section_key' => $sectionKey,
                    'title' => $definition['name'],
                    'blocks' => $blocks,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildDialogCardRowsByKey(): array
    {
        $dialog = $this->getRecord();
        $contactSummary = $this->getContactSummaryViewData();
        $inboxStatus = $this->getDialogInboxStatusViewData();
        $currentBlock = $this->getCurrentDialogBlockViewData($dialog);

        $rows = [
            'id' => $this->dialogCardRow('id', 'ID', (string) $dialog->id),
            'contact_id' => $this->dialogCardRow('contact_id', 'Контакт', $contactSummary['contact_label']),
            'channel_id' => $this->dialogCardRow('channel_id', 'Канал', $this->formatChannelLabel($dialog->channel, 'Неизвестный канал')),
            'status' => $this->dialogCardRow('status', 'Статус', $inboxStatus['current_label']),
            'assigned_user_id' => $this->dialogCardRow('assigned_user_id', 'Ответственный', $contactSummary['assigned_user_label']),
            'stage' => $this->dialogCardRow('stage', 'Этап', $this->dialogOptionLabel('stage', $this->resolveEffectiveDialogStage($dialog), Dialog::stageLabel($this->resolveEffectiveDialogStage($dialog)))),
            'current_block_id' => $this->dialogCardRow('current_block_id', 'Текущий блок', $currentBlock['value']),
            'phone' => $this->dialogCardRow('phone', 'Телефон', $this->formatDialogPhoneLabel($dialog)),
            'external_username' => $this->dialogCardRow('external_username', 'Юзернейм', $this->formatDialogExternalUsernameLabel($dialog)),
            'avatar' => $this->dialogCardRow('avatar', 'Аватарка', filled($this->resolveDialogAvatarUrl($dialog)) ? 'Есть' : '—'),
            'external_chat_id' => $this->dialogCardRow('external_chat_id', 'Внешний ID чата', $dialog->external_chat_id ?: '—'),
            'bot_subscription_status' => $this->dialogCardRow('bot_subscription_status', 'Подписка на бота', $this->dialogOptionLabel('bot_subscription_status', $dialog->bot_subscription_status, $dialog->bot_subscription_status ?: '—')),
            'bot_subscription_changed_at' => $this->dialogCardRow('bot_subscription_changed_at', 'Подписка на бота изменена', $this->formatDialogTimestamp($dialog->bot_subscription_changed_at)),
            'phone_confirmed_at' => $this->dialogCardRow('phone_confirmed_at', 'Телефон подтверждён', $this->formatDialogTimestamp($dialog->phone_confirmed_at)),
            'phone_confirmed_via' => $this->dialogCardRow('phone_confirmed_via', 'Как подтверждён телефон', $this->dialogOptionLabel('phone_confirmed_via', $dialog->phone_confirmed_via, $dialog->phone_confirmed_via ?: '—')),
            'bitrix24_live_chat_id' => $this->dialogCardRow('bitrix24_live_chat_id', 'ID чата Битрикс24', $dialog->bitrix24_live_chat_id ?: '—'),
            'bitrix24_live_status' => $this->dialogCardRow('bitrix24_live_status', 'Статус чата Битрикс24', $this->dialogOptionLabel('bitrix24_live_status', $dialog->bitrix24_live_status, $dialog->bitrix24_live_status ?: '—')),
            'bitrix24_live_last_exported_at' => $this->dialogCardRow('bitrix24_live_last_exported_at', 'Чат Битрикс24 выгружен', $this->formatDialogTimestamp($dialog->bitrix24_live_last_exported_at)),
            'bitrix24_live_last_imported_at' => $this->dialogCardRow('bitrix24_live_last_imported_at', 'Чат Битрикс24 загружен', $this->formatDialogTimestamp($dialog->bitrix24_live_last_imported_at)),
            'last_message_at' => $this->dialogCardRow('last_message_at', 'Последнее сообщение', $this->formatDialogTimestamp($dialog->last_message_at)),
            'last_inbound_message_at' => $this->dialogCardRow('last_inbound_message_at', 'Последнее входящее', $this->formatDialogTimestamp($dialog->last_inbound_at)),
            'last_outbound_message_at' => $this->dialogCardRow('last_outbound_message_at', 'Последнее исходящее', $this->formatDialogTimestamp($dialog->last_outbound_at)),
            'last_message_id' => $this->dialogCardRow('last_message_id', 'ID последнего сообщения', filled($dialog->last_message_id) ? (string) $dialog->last_message_id : '—'),
            'last_inbound_message_id' => $this->dialogCardRow('last_inbound_message_id', 'Последнее входящее', filled($dialog->last_inbound_message_id) ? (string) $dialog->last_inbound_message_id : '—'),
            'last_outbound_message_id' => $this->dialogCardRow('last_outbound_message_id', 'Последнее исходящее', filled($dialog->last_outbound_message_id) ? (string) $dialog->last_outbound_message_id : '—'),
            'created_at' => $this->dialogCardRow('created_at', 'Создан', $this->formatDialogTimestamp($dialog->created_at)),
            'updated_at' => $this->dialogCardRow('updated_at', 'Обновлён', $this->formatDialogTimestamp($dialog->updated_at)),
        ];

        foreach ($this->getDialogFieldsViewData()['fields'] as $field) {
            $fieldKey = (string) ($field['key'] ?? '');

            if ($fieldKey === '') {
                continue;
            }

            $rows[$fieldKey] = $this->dialogCardRow(
                $fieldKey,
                (string) ($field['label'] ?? $fieldKey),
                (string) ($field['value'] ?? '—'),
                [
                    'editable_value' => (string) ($field['editable_value'] ?? ''),
                    'can_edit' => (bool) ($field['can_edit'] ?? false),
                ],
            );
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function dialogCardRow(string $fieldKey, string $fallbackLabel, string $value, array $attributes = []): array
    {
        return [
            'key' => $fieldKey,
            'label' => $this->dialogFieldLabel($fieldKey, $fallbackLabel),
            'value' => trim($value) !== '' ? $value : '—',
        ] + $attributes;
    }

    protected function initializeConversationHistory(): void
    {
        $page = app(LoadDialogMessagesPageAction::class)->handle(
            $this->getRecord(),
            null,
            self::INITIAL_CONVERSATION_MESSAGE_LIMIT,
        );

        $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
        $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
        $this->nextOlderCursor = $page->nextOlderCursor;
        $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);
        $this->syncDialogInboxStatusSelection();
        $this->syncDialogStageSelection();
    }

    /**
     * @return array{
     *     isVisible:bool,
     *     canReply:bool,
     *     blockedReason:?string,
     *     autoReplyEnabled:bool,
     *     replyTextModel:string,
     *     replyFormatModel:string,
     *     replyFormatOptions:array<string, string>,
     *     replyErrorModel:string,
     *     submitMethod:string
     * }
     */
    protected function getReplyComposerViewData(): array
    {
        $replyOwner = $this->resolveReplyOwnerContact();

        return [
            'isVisible' => $this->canCurrentUserManageDialogReplies(),
            'canReply' => $this->canCurrentUserReplyToDialog(),
            'blockedReason' => $this->getDialogReplyBlockedReason(),
            'autoReplyEnabled' => $replyOwner?->isAutoReplyEnabled() ?? false,
            'replyTextModel' => 'dialogReplyText',
            'replyFormatModel' => 'dialogReplyFormat',
            'replyFormatOptions' => Message::textFormatOptions(),
            'replyErrorModel' => 'dialogReplyText',
            'submitMethod' => 'sendDialogReply',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getConversationDisplayModeOptions(): array
    {
        return [
            self::CONVERSATION_DISPLAY_MODE_FORMATTED => 'Обычный вид',
            self::CONVERSATION_DISPLAY_MODE_HTML => 'HTML-код',
        ];
    }

    /**
     * @return array{
     *     current_label:string,
     *     current_tone:string,
     *     is_editable:bool,
     *     status_model:string,
     *     update_method:string,
     *     options:array<string, string>
     * }
     */
    protected function getDialogInboxStatusViewData(): array
    {
        $status = $this->resolveDialogInboxStatus($this->getRecord());

        return [
            'current_label' => $status->label,
            'current_tone' => $status->tone,
            'is_editable' => $this->canCurrentUserManageDialogReplies()
                && $status->code !== DialogInboxStatusData::CODE_NO_NEW,
            'status_model' => 'dialogInboxStatusSelection',
            'update_method' => 'updateDialogInboxStatus',
            'options' => $this->getDialogInboxStatusOptions($status),
        ];
    }

    /**
     * @return array{
     *     current_label:string,
     *     current_tone:string,
     *     is_editable:bool,
     *     blocked_reason:?string,
     *     stage_model:string,
     *     update_method:string,
     *     options:array<string, string>,
     *     steps:list<array{
     *         value:string,
     *         label:string,
     *         tone:string,
     *         color_hex:string,
     *         background_color:string,
     *         border_color:string,
     *         text_color:string,
     *         shadow_color:string,
     *         accent_color:string,
     *         is_current:bool,
     *         is_clickable:bool,
     *         is_completed:bool
     *     }>
     * }
     */
    protected function getDialogStageViewData(): array
    {
        $dialog = $this->getRecord();
        $currentStage = $this->resolveEffectiveDialogStage($dialog);
        $isEditable = $this->canCurrentUserManageDialogStages()
            && $this->getDialogStageBlockedReason() === null;
        $allowedTargets = Dialog::allowedManualTransitionTargets($currentStage);
        $workingStages = Dialog::workingStages();
        $currentIndex = array_search($currentStage, $workingStages, true);
        $currentStageColorData = $this->buildDialogStageStepColorData($currentStage);
        $futureStageColorData = $this->buildNeutralDialogStageStepColorData();
        $stageAccentColorData = collect($workingStages)
            ->mapWithKeys(fn (string $stage): array => [$stage => $this->buildDialogStageStepColorData($stage)['color_hex']])
            ->all();

        return [
            'current_label' => $this->dialogOptionLabel('stage', $currentStage, Dialog::stageLabel($currentStage)),
            'current_tone' => Dialog::stageTone($currentStage),
            'is_editable' => $isEditable,
            'blocked_reason' => null,
            'stage_model' => 'dialogStageSelection',
            'update_method' => 'updateDialogStage',
            'options' => $this->applyDialogDictionaryOptionLabels('stage', Dialog::manualTransitionOptions($currentStage)),
            'steps' => collect($workingStages)
                ->map(function (string $stage, int $index) use ($allowedTargets, $currentIndex, $currentStage, $currentStageColorData, $futureStageColorData, $isEditable, $stageAccentColorData): array {
                    $isCurrent = $stage === $currentStage;
                    $isCompleted = $currentIndex !== false && $index < $currentIndex;
                    $displayColorData = $isCurrent || $isCompleted
                        ? $currentStageColorData
                        : $futureStageColorData;

                    return [
                        'value' => $stage,
                        'label' => $this->dialogOptionLabel('stage', $stage, Dialog::stageLabel($stage)),
                        'tone' => $isCurrent || $isCompleted ? Dialog::stageTone($currentStage) : 'gray',
                        ...$displayColorData,
                        'accent_color' => $isCurrent || $isCompleted
                            ? $currentStageColorData['color_hex']
                            : ($stageAccentColorData[$stage] ?? $futureStageColorData['color_hex']),
                        'is_current' => $isCurrent,
                        'is_clickable' => $isEditable && in_array($stage, $allowedTargets, true),
                        'is_completed' => $isCompleted,
                    ];
                })
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{color_hex:string,background_color:string,border_color:string,text_color:string,shadow_color:string}
     */
    private function buildDialogStageStepColorData(string $stage): array
    {
        $color = app(DialogStageCatalog::class)->colorTokens($stage);

        return [
            'color_hex' => (string) $color['hex'],
            'background_color' => (string) $color['background'],
            'border_color' => (string) $color['border'],
            'text_color' => (string) $color['text'],
            'shadow_color' => (string) $color['soft'],
        ];
    }

    /**
     * @return array{color_hex:string,background_color:string,border_color:string,text_color:string,shadow_color:string}
     */
    private function buildNeutralDialogStageStepColorData(): array
    {
        $color = app(ColorRegistry::class)->resolve(null, null, 'gray');

        return [
            'color_hex' => (string) $color['hex'],
            'background_color' => (string) $color['background'],
            'border_color' => (string) $color['border'],
            'text_color' => (string) $color['text'],
            'shadow_color' => (string) $color['soft'],
        ];
    }

    /**
     * @return array{
     *     can_manage:bool,
     *     available_assignees:array<string, string>
     * }
     */
    protected function getDialogAssigneeViewData(): array
    {
        return [
            'can_manage' => $this->canCurrentUserManageDialogContactOwnership()
                && $this->getRecord()->contact instanceof Contact,
            'available_assignees' => $this->getAssignableUserOptions(),
        ];
    }

    /**
     * @return array{
     *     channel_label:string,
     *     platform_label:string,
     *     avatar_url:?string,
     *     avatar_fallback_label:?string,
     *     messenger_name_label:string,
     *     route_source_label:string,
     *     external_chat_id_label:string,
     *     route_status_label:string,
     *     route_status_tone:string,
     *     route_status_reason:?string,
     *     phone_label:string
     * }
     */
    protected function getDialogHeaderViewData(): array
    {
        $dialog = $this->getRecord();
        $routeStatus = $this->resolveDialogRouteStatus($dialog);

        return [
            'channel_label' => $this->formatChannelLabel($dialog->channel, 'Неизвестный канал'),
            'platform_label' => $dialog->channel?->platform !== null
                ? (Channel::platformOptions()[$dialog->channel->platform] ?? $dialog->channel->platform)
                : '—',
            'avatar_url' => $this->resolveDialogAvatarUrl($dialog),
            'avatar_fallback_label' => $this->formatDialogAvatarFallbackLabel($dialog),
            'messenger_name_label' => $this->formatDialogMessengerNameLabel($dialog),
            'route_source_label' => $this->formatDialogRouteIdentityLabel($dialog),
            'external_chat_id_label' => $dialog->external_chat_id ?: 'Не задан',
            'route_status_label' => $routeStatus->label,
            'route_status_tone' => $routeStatus->tone,
            'route_status_reason' => $routeStatus->blockedReason,
            'phone_label' => $this->formatDialogPhoneLabel($dialog),
        ];
    }

    /**
     * @return array{
     *     is_visible:bool,
     *     status_label:string,
     *     status_tone:string,
     *     history_complete_label:string,
     *     oldest_imported_message_id_label:string,
     *     latest_observed_message_id_label:string,
     *     last_sync_error_label:string
     * }
     */
    protected function getPeerSyncStateViewData(): array
    {
        $dialog = $this->getRecord();
        $channel = $dialog->channel;

        if (
            ! $channel instanceof Channel
            || ! $channel->isAccountConnection()
            || $channel->platform !== Channel::PLATFORM_TELEGRAM
            || ! filled($dialog->external_chat_id)
        ) {
            return [
                'is_visible' => false,
                'status_label' => '—',
                'status_tone' => 'gray',
                'history_complete_label' => '—',
                'oldest_imported_message_id_label' => '—',
                'latest_observed_message_id_label' => '—',
                'last_sync_error_label' => '—',
            ];
        }

        $peerSyncState = ChannelPeerSyncState::query()
            ->where('channel_id', $channel->getKey())
            ->where('external_chat_id', (string) $dialog->external_chat_id)
            ->first();

        if (! $peerSyncState instanceof ChannelPeerSyncState) {
            return [
                'is_visible' => true,
                'status_label' => 'Нет sync-state',
                'status_tone' => 'gray',
                'history_complete_label' => '—',
                'oldest_imported_message_id_label' => '—',
                'latest_observed_message_id_label' => '—',
                'last_sync_error_label' => '—',
            ];
        }

        return [
            'is_visible' => true,
            'status_label' => $peerSyncState->getBackfillStatusLabel(),
            'status_tone' => $peerSyncState->getBackfillStatusColor(),
            'history_complete_label' => $this->formatPeerSyncTimestamp($peerSyncState->history_complete_at),
            'oldest_imported_message_id_label' => $peerSyncState->oldest_imported_message_id ?: '—',
            'latest_observed_message_id_label' => $peerSyncState->latest_observed_message_id ?: '—',
            'last_sync_error_label' => $peerSyncState->last_sync_error ?: '—',
        ];
    }

    /**
     * @return array{
     *     contact_label:string,
     *     contact_id:int|null,
     *     phones_label:string,
     *     assigned_user_label:string
     * }
     */
    protected function getContactSummaryViewData(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;

        return [
            'contact_label' => $contact instanceof Contact
                ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
                : 'Контакт не найден',
            'contact_id' => $contact?->id,
            'phones_label' => $this->formatContactPhonesLabel($contact),
            'assigned_user_label' => $this->formatAssignedUserLabel($contact),
        ];
    }

    /**
     * @return array{
     *     is_visible: bool,
     *     fields: list<array{key: string, label: string, value: string, editable_value: string, value_type: string, is_truncated: bool, can_edit: bool}>
     * }
     */
    protected function getDialogFieldsViewData(): array
    {
        $fieldsPayload = $this->getRecord()->fields_payload;

        if (! is_array($fieldsPayload)) {
            return [
                'is_visible' => true,
                'fields' => [],
            ];
        }

        $fields = collect($fieldsPayload)
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key)
                && ! str_starts_with($key, '_'))
            ->map(function (mixed $value, string $key) use ($fieldsPayload): array {
                $formattedValue = $this->formatDialogFieldValue($value);
                $editableValue = $this->formatDialogFieldEditableValue($value);

                return [
                    'key' => $key,
                    'label' => $this->dialogFieldLabel($key, $key),
                    'value' => $formattedValue['value'],
                    'editable_value' => array_key_exists($key, $this->dialogFieldDraftValues)
                        ? (string) $this->dialogFieldDraftValues[$key]
                        : $editableValue,
                    'value_type' => $formattedValue['type'],
                    'is_truncated' => $formattedValue['is_truncated'],
                    'can_edit' => $this->canEditDialogFieldValue($key, $fieldsPayload),
                ];
            })
            ->sortBy('key', SORT_NATURAL)
            ->values()
            ->all();

        return [
            'is_visible' => true,
            'fields' => $fields,
        ];
    }

    /**
     * @return array{
     *     rows: list<array{
     *         key: string,
     *         label: string,
     *         value: string,
     *         detail: ?string,
     *         url: ?string,
     *         value_role: ?string,
     *         tone: ?string
     *     }>
     * }
     */
    protected function getDialogSystemFieldsViewData(): array
    {
        $dialog = $this->getRecord();
        $contactSummary = $this->getContactSummaryViewData();
        $inboxStatus = $this->getDialogInboxStatusViewData();
        $currentBlock = $this->getCurrentDialogBlockViewData($dialog);

        return [
            'rows' => [
                $this->dialogSystemFieldRow('id', 'ID', (string) $dialog->id),
                $this->dialogSystemFieldRow(
                    'contact_id',
                    'Контакт',
                    $contactSummary['contact_label'],
                    null,
                    'dialog-contact-label',
                    null,
                    $this->getContactViewUrl(),
                ),
                $this->dialogSystemFieldRow('channel_id', 'Канал', $this->formatChannelLabel($dialog->channel, 'Неизвестный канал'), null, 'dialog-channel-label'),
                $this->dialogSystemFieldRow('status', 'Статус', $inboxStatus['current_label']),
                $this->dialogSystemFieldRow('assigned_user_id', 'Ответственный', $contactSummary['assigned_user_label']),
                $this->dialogSystemFieldRow(
                    'current_block_id',
                    'Текущий блок',
                    $currentBlock['value'],
                    $currentBlock['detail'],
                    'dialog-current-block',
                    $currentBlock['tone'],
                ),
                $this->dialogSystemFieldRow('created_at', 'Создан', $this->formatDialogTimestamp($dialog->created_at)),
                $this->dialogSystemFieldRow('updated_at', 'Обновлён', $this->formatDialogTimestamp($dialog->updated_at)),
                $this->dialogSystemFieldRow(
                    'last_message_at',
                    'Последнее сообщение',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_message_at),
                        $this->formatDialogMessagePreview($dialog, 'last_message_id', $dialog->last_message_preview),
                    ),
                ),
                $this->dialogSystemFieldRow(
                    'last_inbound_message_at',
                    'Последнее входящее',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_inbound_at),
                        $this->formatDialogMessagePreview($dialog, 'last_inbound_message_id', $dialog->last_inbound_message_preview),
                    ),
                    null,
                    null,
                    null,
                    null,
                    'Последнее входящее',
                ),
                $this->dialogSystemFieldRow(
                    'last_outbound_message_at',
                    'Последнее исходящее',
                    $this->formatDialogSnapshotLine(
                        $this->formatDialogTimestamp($dialog->last_outbound_at),
                        $this->formatDialogMessagePreview($dialog, 'last_outbound_message_id', $dialog->last_outbound_message_preview),
                    ),
                    null,
                    null,
                    null,
                    null,
                    'Последнее исходящее',
                ),
                $this->dialogSystemFieldRow('phone', 'Телефон', $this->formatDialogPhoneLabel($dialog)),
            ],
        ];
    }

    /**
     * @return array{
     *     is_visible: bool,
     *     rows: list<array{
     *         key: string,
     *         label: string,
     *         value: string,
     *         detail: ?string,
     *         url: ?string,
     *         value_role: ?string,
     *         tone: ?string
     *     }>
     * }
     */
    protected function getDialogAutomationDiagnosticsViewData(): array
    {
        $dialog = $this->getRecord();
        $latestInbound = Message::query()
            ->with(['channel', 'contact.phoneNumbers', 'dialog.dialogStage'])
            ->where('dialog_id', $dialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->where('message_kind', Message::KIND_INBOUND_USER)
            ->orderByDesc('id')
            ->first();

        if (! $latestInbound instanceof Message) {
            return [
                'is_visible' => true,
                'rows' => [
                    $this->dialogSystemFieldRow(
                        'automation_latest_inbound',
                        'Последнее входящее',
                        'Нет входящих сообщений',
                        'Диагностика автоответов появится после первого входящего сообщения.',
                        tone: 'muted',
                    ),
                ],
            ];
        }

        $v3Diagnostics = $this->diagnoseLatestInboundV3Start($dialog, $latestInbound);
        $legacyCutoverLog = $this->latestMessageActivityLog($dialog, $latestInbound, 'bot.reply_skipped_legacy_cutover');
        $automationLogs = $this->latestMessageAutomationActivityLogs($dialog, $latestInbound);
        $currentBlock = $this->getCurrentDialogBlockViewData($dialog);

        $rows = [
            $this->dialogSystemFieldRow(
                'automation_latest_inbound',
                'Последнее входящее',
                $this->formatAutomationInboundMessageLabel($latestInbound),
                $this->formatAutomationInboundMessageDetail($latestInbound),
            ),
            $this->dialogSystemFieldRow(
                'automation_v3_start',
                'V3-start',
                $v3Diagnostics['status_label'],
                $v3Diagnostics['detail'],
                tone: $v3Diagnostics['tone'],
            ),
            $this->dialogSystemFieldRow(
                'automation_current_block',
                'Активный сценарий',
                $currentBlock['value'],
                $currentBlock['detail'],
                tone: $currentBlock['tone'],
            ),
            $this->dialogSystemFieldRow(
                'automation_legacy_cutover',
                'Старый автоответчик',
                $legacyCutoverLog instanceof ChannelActivityLog ? 'Пропущен из-за V3 cutover' : 'Нет события пропуска',
                $legacyCutoverLog instanceof ChannelActivityLog
                    ? $this->formatAutomationActivityLogDetail($legacyCutoverLog)
                    : 'Если V3 не стартовал и legacy не отключён, здесь появится событие старого автоответчика.',
                tone: $legacyCutoverLog instanceof ChannelActivityLog ? 'warning' : 'muted',
            ),
        ];

        if (($v3Diagnostics['candidate_label'] ?? null) !== null) {
            $rows[] = $this->dialogSystemFieldRow(
                'automation_v3_candidate',
                'Ближайший кандидат',
                (string) $v3Diagnostics['candidate_label'],
                $v3Diagnostics['candidate_detail'],
                tone: $v3Diagnostics['candidate_tone'],
            );
        }

        if ($automationLogs->isNotEmpty()) {
            $rows[] = $this->dialogSystemFieldRow(
                'automation_last_event',
                'Последнее событие',
                (string) $automationLogs->first()?->event,
                $this->formatAutomationActivityLogDetail($automationLogs->first()),
                tone: $this->automationLogTone($automationLogs->first()),
            );
        }

        return [
            'is_visible' => true,
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *     status_label: string,
     *     detail: ?string,
     *     tone: ?string,
     *     candidate_label?: ?string,
     *     candidate_detail?: ?string,
     *     candidate_tone?: ?string
     * }
     */
    protected function diagnoseLatestInboundV3Start(Dialog $dialog, Message $message): array
    {
        $versions = $this->publishedV3ScenarioVersionsForMessage($message);

        if ($versions->isEmpty()) {
            return [
                'status_label' => 'Нет опубликованного V3 для канала',
                'detail' => 'Для канала последнего входящего не найден активный опубликованный V3-сценарий.',
                'tone' => 'warning',
            ];
        }

        $matchingCandidates = [];

        foreach ($versions as $version) {
            $runtime = data_get($version->schema_payload, 'builder_v3_runtime');

            if (! is_array($runtime)) {
                continue;
            }

            foreach ($this->v3DiagnosticEntrypoints($runtime) as $entrypoint) {
                if (! is_array($entrypoint) || ! $this->v3DiagnosticEntrypointMatchesMessageValue($message, $entrypoint)) {
                    continue;
                }

                $matchingCandidates[] = $this->diagnoseV3EntrypointCandidate($dialog, $message, $version, $runtime, $entrypoint);
            }
        }

        $successfulCandidate = collect($matchingCandidates)
            ->first(fn (array $candidate): bool => $candidate['reasons'] === []);

        if (is_array($successfulCandidate)) {
            return [
                'status_label' => 'Нашёл подходящий V3-start',
                'detail' => 'По условиям опубликованного V3 этот entrypoint должен стартовать.',
                'tone' => 'success',
                'candidate_label' => $successfulCandidate['label'],
                'candidate_detail' => 'Все условия входа выполнены.',
                'candidate_tone' => 'success',
            ];
        }

        if ($matchingCandidates === []) {
            return [
                'status_label' => 'V3-start не найден',
                'detail' => 'В опубликованных V3 entrypoint-ах нет совпадения по тексту или параметру: '.$this->formatAutomationMessageText($message),
                'tone' => 'warning',
            ];
        }

        $candidate = $matchingCandidates[0];

        return [
            'status_label' => 'V3-start отклонён условиями',
            'detail' => implode('; ', $candidate['reasons']),
            'tone' => 'warning',
            'candidate_label' => $candidate['label'],
            'candidate_detail' => implode('; ', $candidate['reasons']),
            'candidate_tone' => 'warning',
        ];
    }

    /**
     * @return Collection<int, ScenarioVersion>
     */
    protected function publishedV3ScenarioVersionsForMessage(Message $message): Collection
    {
        if ($message->channel_id === null) {
            return collect();
        }

        $scenarioCodes = DB::table('scenario_channel_bindings')
            ->where('channel_id', $message->channel_id)
            ->where('is_active', true)
            ->pluck('scenario_code')
            ->filter()
            ->values()
            ->all();

        if ($scenarioCodes === []) {
            return collect();
        }

        $scenarioOrder = array_flip(array_map('strval', $scenarioCodes));

        return ScenarioVersion::query()
            ->with('scenario:id,code,name')
            ->where('status', ScenarioVersion::STATUS_PUBLISHED)
            ->whereHas('scenario', function ($query) use ($scenarioCodes): void {
                $query
                    ->whereIn('code', $scenarioCodes)
                    ->where('is_active', true)
                    ->where('is_archived', false);
            })
            ->orderByDesc('version_number')
            ->get()
            ->filter(fn (ScenarioVersion $version): bool => is_array(data_get($version->schema_payload, 'builder_v3_runtime.entrypoints')))
            ->sort(function (ScenarioVersion $left, ScenarioVersion $right) use ($scenarioOrder): int {
                $leftScenarioOrder = $scenarioOrder[(string) ($left->scenario?->code ?? '')] ?? PHP_INT_MAX;
                $rightScenarioOrder = $scenarioOrder[(string) ($right->scenario?->code ?? '')] ?? PHP_INT_MAX;

                return [
                    $leftScenarioOrder,
                    -((int) $left->version_number),
                    -((int) $left->id),
                ] <=> [
                    $rightScenarioOrder,
                    -((int) $right->version_number),
                    -((int) $right->id),
                ];
            })
            ->values();
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @return list<array<string, mixed>>
     */
    protected function v3DiagnosticEntrypoints(array $runtime): array
    {
        $entrypoints = is_array($runtime['entrypoints'] ?? null) ? $runtime['entrypoints'] : [];

        return collect($entrypoints)
            ->filter(fn (mixed $entrypoint): bool => is_array($entrypoint))
            ->sort(fn (array $left, array $right): int => $this->compareV3DiagnosticEntrypoints($left, $right, $runtime))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     * @param  array<string, mixed>  $runtime
     */
    protected function compareV3DiagnosticEntrypoints(array $left, array $right, array $runtime): int
    {
        return [
            $this->v3DiagnosticEntrypointPriority($right),
            $this->v3DiagnosticEntrypointDisplayOrder($right, $runtime),
            $this->v3DiagnosticEntrypointDisplayId($right, $runtime),
        ] <=> [
            $this->v3DiagnosticEntrypointPriority($left),
            $this->v3DiagnosticEntrypointDisplayOrder($left, $runtime),
            $this->v3DiagnosticEntrypointDisplayId($left, $runtime),
        ];
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticEntrypointPriority(array $entrypoint): int
    {
        return max(1, min(100, (int) ($entrypoint['priority'] ?? 10)));
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     * @param  array<string, mixed>  $runtime
     */
    protected function v3DiagnosticEntrypointDisplayOrder(array $entrypoint, array $runtime): int
    {
        $displayId = $this->v3DiagnosticEntrypointDisplayId($entrypoint, $runtime);

        return is_numeric($displayId) ? (int) $displayId : PHP_INT_MIN;
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     * @param  array<string, mixed>  $runtime
     */
    protected function v3DiagnosticEntrypointDisplayId(array $entrypoint, array $runtime): string
    {
        $displayId = trim((string) ($entrypoint['display_id'] ?? $entrypoint['display_number'] ?? ''));

        if ($displayId !== '') {
            return $displayId;
        }

        $blockId = trim((string) ($entrypoint['block_id'] ?? ''));
        $block = is_array($runtime['blocks'] ?? null) ? ($runtime['blocks'][$blockId] ?? null) : null;

        if (is_array($block)) {
            $displayId = trim((string) ($block['display_number'] ?? $block['card_id'] ?? $block['db_id'] ?? $block['id'] ?? ''));
        }

        return $displayId !== '' ? $displayId : $blockId;
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticEntrypointMatchesMessageValue(Message $message, array $entrypoint): bool
    {
        $match = (string) ($entrypoint['match'] ?? AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER);

        if ($match === AutoReplyRule::MATCH_SCOPE_ANY_INBOUND) {
            return true;
        }

        foreach ($entrypoint['values'] ?? [] as $value) {
            if ($this->messageMatchesV3DiagnosticValue($message, $match, (string) $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $runtime
     * @param  array<string, mixed>  $entrypoint
     * @return array{label:string,reasons:list<string>}
     */
    protected function diagnoseV3EntrypointCandidate(
        Dialog $dialog,
        Message $message,
        ScenarioVersion $version,
        array $runtime,
        array $entrypoint,
    ): array {
        $reasons = [];
        $channelIds = collect($entrypoint['channel_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($message->channel_id === null || ! in_array((int) $message->channel_id, $channelIds, true)) {
            $reasons[] = 'канал сообщения не входит в список каналов entrypoint-а';
        }

        $startEventReason = $this->v3DiagnosticStartEventFailureReason($dialog, $message, $entrypoint);

        if ($startEventReason !== null) {
            $reasons[] = $startEventReason;
        }

        $contactPhoneReason = $this->v3DiagnosticContactPhoneFailureReason($message, $entrypoint);

        if ($contactPhoneReason !== null) {
            $reasons[] = $contactPhoneReason;
        }

        $dialogPhoneReason = $this->v3DiagnosticDialogPhoneFailureReason($dialog, $entrypoint);

        if ($dialogPhoneReason !== null) {
            $reasons[] = $dialogPhoneReason;
        }

        $expressionReason = $this->v3DiagnosticExpressionFailureReason($message, $entrypoint);

        if ($expressionReason !== null) {
            $reasons[] = $expressionReason;
        }

        $tagReason = $this->v3DiagnosticTagFailureReason($message, $entrypoint);

        if ($tagReason !== null) {
            $reasons[] = $tagReason;
        }

        $blockId = trim((string) ($entrypoint['block_id'] ?? ''));
        $block = is_array($runtime['blocks'] ?? null) ? ($runtime['blocks'][$blockId] ?? null) : null;

        if (! is_array($block)) {
            $reasons[] = 'целевой блок entrypoint-а не найден в опубликованном runtime';
        }

        $displayNumber = trim((string) ($entrypoint['display_id'] ?? data_get($block, 'display_number', $blockId)));
        $title = trim((string) data_get($block, 'title', ''));
        $scenarioCode = (string) ($version->scenario?->code ?? 'V3');

        return [
            'label' => '#'.($displayNumber !== '' ? $displayNumber : $blockId).' · '.($title !== '' ? $title : 'entrypoint').' · '.$scenarioCode.' v'.$version->version_number,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticStartEventFailureReason(Dialog $dialog, Message $message, array $entrypoint): ?string
    {
        $event = trim((string) ($entrypoint['event'] ?? 'message'));

        if ($event === '' || $event === 'message') {
            return $message->message_kind === Message::KIND_INBOUND_USER
                ? null
                : 'entrypoint ждёт обычное входящее сообщение';
        }

        if ($event === 'message_in_stage') {
            $expectedStageKey = trim((string) ($entrypoint['stage_key'] ?? ''));
            $actualStageKey = app(DialogStageCatalog::class)->keyForDialog($dialog)
                ?? app(ResolveDialogStageAction::class)->handle($dialog);

            return $expectedStageKey !== '' && $actualStageKey === $expectedStageKey
                ? null
                : 'entrypoint ждёт сообщение на этапе '.$this->formatAutomationEmptyValue($expectedStageKey).', сейчас '.$this->formatAutomationEmptyValue($actualStageKey);
        }

        if ($event === 'stage_changed') {
            return 'entrypoint запускается только при смене этапа, а последнее сообщение обычное входящее';
        }

        return 'неизвестный тип события entrypoint-а: '.$event;
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticContactPhoneFailureReason(Message $message, array $entrypoint): ?string
    {
        $condition = trim((string) ($entrypoint['contact_phone_condition'] ?? ''));

        if ($condition === '') {
            return null;
        }

        $hasPhone = $message->contact_id !== null
            && ContactPhoneNumber::query()->where('contact_id', $message->contact_id)->exists();

        return match ($condition) {
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE => $hasPhone ? null : 'у контакта нет телефона, а entrypoint требует телефон',
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE => $hasPhone ? 'у контакта есть телефон, а entrypoint ждёт отсутствие телефона' : null,
            default => 'неизвестное условие телефона контакта: '.$condition,
        };
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticExpressionFailureReason(Message $message, array $entrypoint): ?string
    {
        $expression = trim((string) ($entrypoint['expression'] ?? ''));

        if ($expression === '') {
            return null;
        }

        try {
            return app(ScenarioEdgeExpressionCondition::class)->evaluate($expression, $message)
                ? null
                : 'expression-условие entrypoint-а вернуло false';
        } catch (Throwable $throwable) {
            return 'expression-условие entrypoint-а не выполнилось: '.$throwable->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticDialogPhoneFailureReason(Dialog $dialog, array $entrypoint): ?string
    {
        $condition = trim((string) ($entrypoint['dialog_phone_condition'] ?? ''));

        if ($condition === '') {
            return null;
        }

        $hasPhone = filled($dialog->confirmed_phone_raw) || filled($dialog->confirmed_phone_normalized);

        return match ($condition) {
            AutoReplyRule::CONTACT_PHONE_CONDITION_HAS_PHONE => $hasPhone ? null : 'в диалоге нет подтверждённого телефона, а entrypoint требует телефон',
            AutoReplyRule::CONTACT_PHONE_CONDITION_MISSING_PHONE => $hasPhone ? 'в диалоге есть подтверждённый телефон, а entrypoint ждёт отсутствие телефона' : null,
            default => 'неизвестное условие телефона диалога: '.$condition,
        };
    }

    /**
     * @param  array<string, mixed>  $entrypoint
     */
    protected function v3DiagnosticTagFailureReason(Message $message, array $entrypoint): ?string
    {
        $condition = is_array($entrypoint['tag_condition'] ?? null) ? $entrypoint['tag_condition'] : [];

        if ((bool) ($condition['enabled'] ?? false) !== true) {
            return null;
        }

        $tagIds = collect($condition['tag_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($tagIds === [] || $message->contact_id === null) {
            return 'в entrypoint включено условие по тегам, но теги не заданы';
        }

        $contactTagIds = DB::table('contact_tag')
            ->where('contact_id', $message->contact_id)
            ->pluck('tag_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
        $matchedTagIds = array_intersect($tagIds, $contactTagIds);
        $mode = (string) ($condition['mode'] ?? 'has_all');
        $passes = match ($mode) {
            'has_any' => $matchedTagIds !== [],
            'has_none' => $matchedTagIds === [],
            default => count($matchedTagIds) === count($tagIds),
        };

        return $passes ? null : 'условие по тегам entrypoint-а не выполнено';
    }

    protected function messageMatchesV3DiagnosticValue(Message $message, string $match, string $expectedValue): bool
    {
        $expectedValue = $this->normalizeV3DiagnosticText($expectedValue);
        $messageText = $this->normalizeV3DiagnosticText((string) $message->text);
        $messageParameter = $this->normalizeV3DiagnosticText((string) $message->message_parameter);

        if ($expectedValue === '') {
            return false;
        }

        return match ($match) {
            'contains', AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT => str_contains($messageText, $expectedValue)
                || str_contains($messageParameter, $expectedValue),
            'starts', 'starts_with' => str_starts_with($messageText, $expectedValue)
                || str_starts_with($messageParameter, $expectedValue),
            AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER => $messageParameter === $expectedValue,
            AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD => $messageText === $expectedValue,
            AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER => $messageText === $expectedValue
                || $messageParameter === $expectedValue,
            default => $messageText === $expectedValue || $messageParameter === $expectedValue,
        };
    }

    protected function normalizeV3DiagnosticText(string $text): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text));
    }

    /**
     * @return Collection<int, ChannelActivityLog>
     */
    protected function latestMessageAutomationActivityLogs(Dialog $dialog, Message $message): Collection
    {
        return ChannelActivityLog::query()
            ->where('channel_id', $dialog->channel_id)
            ->where('created_at', '>=', ($message->created_at ?? now())->copy()->subMinutes(2))
            ->where('created_at', '<=', ($message->created_at ?? now())->copy()->addMinutes(2))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->filter(function (ChannelActivityLog $log) use ($message): bool {
                return $this->automationActivityLogMatchesMessage($log, $message);
            })
            ->values();
    }

    protected function automationActivityLogMatchesMessage(ChannelActivityLog $log, Message $message): bool
    {
        $context = is_array($log->context) ? $log->context : [];

        if ((int) ($context['message_id'] ?? 0) === (int) $message->id) {
            return true;
        }

        return $this->automationActivityLogContextValueMatches($context, 'provider_event_key', $message->provider_event_key)
            || $this->automationActivityLogContextValueMatches($context, 'external_message_id', $message->external_message_id);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function automationActivityLogContextValueMatches(array $context, string $key, mixed $messageValue): bool
    {
        $contextValue = trim((string) ($context[$key] ?? ''));
        $messageValue = trim((string) $messageValue);

        return $contextValue !== '' && $messageValue !== '' && $contextValue === $messageValue;
    }

    protected function latestMessageActivityLog(Dialog $dialog, Message $message, string $event): ?ChannelActivityLog
    {
        return $this->latestMessageAutomationActivityLogs($dialog, $message)
            ->first(fn (ChannelActivityLog $log): bool => $log->event === $event);
    }

    protected function formatAutomationInboundMessageLabel(Message $message): string
    {
        return '#'.$message->id.' · '.$this->formatDialogTimestamp($message->received_at).' · '.$this->formatAutomationMessageText($message);
    }

    protected function formatAutomationInboundMessageDetail(Message $message): string
    {
        return 'provider event: '.$this->formatAutomationEmptyValue($message->provider_event_key)
            .' · external message: '.$this->formatAutomationEmptyValue($message->external_message_id);
    }

    protected function formatAutomationMessageText(Message $message): string
    {
        $text = trim((string) ($message->text ?: $message->message_parameter));

        if ($text === '') {
            return '—';
        }

        return mb_strlen($text) > 80 ? mb_substr($text, 0, 80).'…' : $text;
    }

    protected function formatAutomationActivityLogDetail(?ChannelActivityLog $log): ?string
    {
        if (! $log instanceof ChannelActivityLog) {
            return null;
        }

        return $this->formatDialogTimestamp($log->created_at).' · '.$log->message;
    }

    protected function automationLogTone(?ChannelActivityLog $log): ?string
    {
        return match ($log?->level) {
            'warning', 'error' => 'warning',
            default => null,
        };
    }

    protected function formatAutomationEmptyValue(mixed $value): string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : '—';
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     value: string,
     *     detail: ?string,
     *     url: ?string,
     *     value_role: ?string,
     *     tone: ?string
     * }
     */
    protected function dialogSystemFieldRow(
        string $fieldKey,
        string $fallbackLabel,
        string $value,
        ?string $detail = null,
        ?string $valueRole = null,
        ?string $tone = null,
        ?string $url = null,
        ?string $displayLabel = null,
    ): array {
        return [
            'key' => $fieldKey,
            'label' => filled($displayLabel) ? $displayLabel : $this->dialogFieldLabel($fieldKey, $fallbackLabel),
            'value' => trim($value) !== '' ? $value : '—',
            'detail' => filled($detail) ? $detail : null,
            'url' => filled($url) ? $url : null,
            'value_role' => $valueRole,
            'tone' => $tone,
        ];
    }

    /**
     * @return array{value: string, detail: ?string, tone: ?string}
     */
    protected function getCurrentDialogBlockViewData(Dialog $dialog): array
    {
        $activeRuns = ScenarioRun::query()
            ->where('dialog_id', $dialog->id)
            ->where('status', ScenarioRun::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get(['id', 'scenario_code', 'current_step', 'state_payload', 'status']);

        /** @var ScenarioRun|null $run */
        $run = $activeRuns->first();

        if (! $run instanceof ScenarioRun) {
            $run = ScenarioRun::query()
                ->where('dialog_id', $dialog->id)
                ->orderByDesc('id')
                ->first(['id', 'scenario_code', 'current_step', 'state_payload', 'status']);
        }

        if (! $run instanceof ScenarioRun) {
            return [
                'value' => 'Сценарий не запускался',
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $currentBlockId = $this->resolveCurrentDialogBlockId($dialog, $run, $activeRuns->contains('id', $run->id));

        $detailParts = [
            'run #'.$run->id,
            $run->status,
        ];

        if ($activeRuns->count() > 1) {
            $detailParts[] = 'найдено несколько активных запусков';
        }

        if ($currentBlockId === '') {
            return [
                'value' => 'Блок не определён · '.implode(' · ', $detailParts),
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $publishedVersionId = (int) data_get($statePayload, 'v3.published_version_id', 0);

        if ($publishedVersionId < 1) {
            return [
                'value' => $currentBlockId.' · сценарий без V3-схемы · '.implode(' · ', $detailParts),
                'detail' => null,
                'tone' => 'muted',
            ];
        }

        $detailParts[] = 'v'.$publishedVersionId;
        $blockViewData = $this->resolvePublishedV3BlockViewData($publishedVersionId, $currentBlockId);
        $blockTitle = $blockViewData['title'];
        $displayNumber = $blockViewData['display_number'] ?? $currentBlockId;

        return [
            'value' => '#'.$displayNumber.' · '.($blockTitle ?? 'блок не найден').' · '.implode(' · ', $detailParts),
            'detail' => null,
            'tone' => $blockTitle !== null ? null : 'warning',
        ];
    }

    protected function resolveCurrentDialogBlockId(Dialog $dialog, ScenarioRun $run, bool $isActiveRun): string
    {
        $statePayload = is_array($run->state_payload) ? $run->state_payload : [];
        $currentBlockId = trim((string) data_get($statePayload, 'v3.current_block_id', ''));

        if ($currentBlockId !== '') {
            return $currentBlockId;
        }

        $currentStep = filled($run->current_step) ? trim((string) $run->current_step) : '';

        if ($currentStep !== '') {
            return $currentStep;
        }

        if (! $isActiveRun) {
            $lastKnownBlockId = trim((string) data_get($statePayload, 'v3.last_known_block_id', ''));

            if ($lastKnownBlockId !== '') {
                return $lastKnownBlockId;
            }

            return $this->resolveLastKnownScenarioMessageBlockId($dialog, $run) ?? '';
        }

        return '';
    }

    protected function resolveLastKnownScenarioMessageBlockId(Dialog $dialog, ScenarioRun $run): ?string
    {
        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->whereNotNull('raw_payload')
            ->orderByDesc('id')
            ->limit(100)
            ->get(['id', 'raw_payload'])
            ->map(function (Message $message) use ($run): ?string {
                $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

                if ((int) data_get($rawPayload, 'v3.scenario_run_id', 0) !== (int) $run->id) {
                    return null;
                }

                $blockId = trim((string) data_get($rawPayload, 'v3.block_id', ''));

                return $blockId !== '' ? $blockId : null;
            })
            ->filter()
            ->first();
    }

    /**
     * @return array{title: ?string, display_number: ?string}
     */
    protected function resolvePublishedV3BlockViewData(int $publishedVersionId, string $currentBlockId): array
    {
        $version = ScenarioVersion::query()->find($publishedVersionId, ['id', 'schema_payload']);

        if (! $version instanceof ScenarioVersion) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $schemaPayload = is_array($version->schema_payload) ? $version->schema_payload : [];
        $blocks = data_get($schemaPayload, 'builder_v3_runtime.blocks', []);

        if (! is_array($blocks)) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $block = $blocks[$currentBlockId] ?? null;

        if (! is_array($block)) {
            $block = collect($blocks)->first(function (mixed $candidate) use ($currentBlockId): bool {
                if (! is_array($candidate)) {
                    return false;
                }

                return trim((string) ($candidate['id'] ?? '')) === $currentBlockId
                    || trim((string) ($candidate['card_id'] ?? '')) === $currentBlockId;
            });
        }

        if (! is_array($block)) {
            return [
                'title' => null,
                'display_number' => null,
            ];
        }

        $title = trim((string) ($block['title'] ?? ''));
        $displayNumber = trim((string) ($block['display_number'] ?? ''));

        if ($displayNumber === '') {
            $displayNumber = trim((string) ($block['card_id'] ?? ''));
        }

        if ($displayNumber === '') {
            $displayNumber = trim((string) ($block['id'] ?? ''));
        }

        return [
            'title' => $title !== '' ? $title : null,
            'display_number' => $displayNumber !== '' ? $displayNumber : null,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function getDialogFieldLabels(): array
    {
        return $this->dialogFieldLabels ??= FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_DIALOG);
    }

    protected function dialogFieldLabel(string $fieldKey, string $fallback): string
    {
        return FieldDictionaryField::labelFrom($this->getDialogFieldLabels(), $fieldKey, $fallback);
    }

    protected function dialogOptionLabel(string $fieldKey, mixed $value, string $fallback): string
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $fallback);
    }

    protected function fillDialogFieldDraftValues(): void
    {
        $this->dialogFieldDraftValues = $this->currentEditableDialogFieldValues();
        $this->dialogFieldDraftDirty = false;
    }

    /**
     * @return array<string, string>
     */
    protected function currentEditableDialogFieldValues(): array
    {
        $fieldsPayload = $this->getRecord()->fields_payload;

        if (! is_array($fieldsPayload)) {
            return [];
        }

        return collect($fieldsPayload)
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key)
                && $this->canEditDialogFieldValue($key, $fieldsPayload))
            ->mapWithKeys(fn (mixed $value, string $key): array => [
                $key => $this->formatDialogFieldEditableValue($value),
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    protected function changedDialogFieldDraftValues(): array
    {
        $currentValues = $this->currentEditableDialogFieldValues();
        $changes = [];

        foreach ($currentValues as $fieldKey => $currentValue) {
            $draftValue = array_key_exists($fieldKey, $this->dialogFieldDraftValues)
                ? (string) $this->dialogFieldDraftValues[$fieldKey]
                : $currentValue;

            if ($draftValue !== $currentValue) {
                $changes[$fieldKey] = $draftValue;
            }
        }

        return $changes;
    }

    protected function refreshDialogDraftDirtyState(): void
    {
        $this->dialogFieldDraftDirty = $this->changedDialogFieldDraftValues() !== []
            || $this->hasDialogAssigneeDraftChanges();
    }

    protected function hasDialogAssigneeDraftChanges(): bool
    {
        if (! $this->isDialogAssigneeEditing) {
            return false;
        }

        $contact = $this->resolveReplyOwnerContact();
        $currentAssigneeId = $contact instanceof Contact && filled($contact->assigned_user_id)
            ? (string) $contact->assigned_user_id
            : '';

        return $this->selectedDialogAssigneeId !== $currentAssigneeId;
    }

    protected function persistDialogAssigneeDraftValue(): void
    {
        if (! $this->canCurrentUserManageDialogContactOwnership()) {
            throw ValidationException::withMessages([
                'dialog_assignee' => 'Недостаточно прав для изменения ответственного.',
            ]);
        }

        $contact = $this->resolveReplyOwnerContact();

        if (! $contact instanceof Contact) {
            throw ValidationException::withMessages([
                'dialog_assignee' => 'У диалога нет связанного контакта.',
            ]);
        }

        $employee = $this->resolveCurrentEmployee();
        $assigneeId = $this->selectedDialogAssigneeId !== ''
            ? (int) $this->selectedDialogAssigneeId
            : null;

        app(SetContactAssigneeAction::class)->handle($contact, $employee, $assigneeId);

        $this->refreshDialogRecord();
    }

    /**
     * @param  array<string, string>  $values
     */
    protected function persistDialogFieldValues(array $values): void
    {
        DB::transaction(function () use ($values): void {
            $dialog = Dialog::query()
                ->whereKey($this->getRecord()->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $fieldsPayload = is_array($dialog->fields_payload) ? $dialog->fields_payload : [];

            foreach ($values as $fieldKey => $valueText) {
                if (mb_strlen($valueText) > self::DIALOG_FIELD_VALUE_MAX_LENGTH) {
                    throw ValidationException::withMessages([
                        'dialog_field_value' => 'Значение поля диалога не должно быть длиннее 2000 символов.',
                    ]);
                }

                if (! $this->canEditDialogFieldValue($fieldKey, $fieldsPayload)) {
                    throw ValidationException::withMessages([
                        'dialog_field_key' => 'Это поле диалога нельзя изменить из карточки.',
                    ]);
                }

                $fieldsPayload[$fieldKey] = $this->normalizeEditedDialogFieldValue(
                    $valueText,
                    $fieldsPayload[$fieldKey] ?? null,
                );
            }

            $encoded = json_encode($fieldsPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if ($encoded === false || strlen($encoded) > self::DIALOG_FIELDS_MAX_BYTES) {
                throw ValidationException::withMessages([
                    'dialog_field_value' => 'Поля диалога стали слишком большими для сохранения.',
                ]);
            }

            $dialog->forceFill(['fields_payload' => $fieldsPayload])->save();
        });

        $this->refreshDialogRecord();
    }

    /**
     * @param  array<string, string>  $fallbackOptions
     * @return array<string, string>
     */
    protected function applyDialogDictionaryOptionLabels(string $fieldKey, array $fallbackOptions): array
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return collect($fallbackOptions)
            ->mapWithKeys(fn (string $label, string $value): array => [
                $value => FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $label),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $fieldsPayload
     */
    protected function canEditDialogFieldValue(string $fieldKey, array $fieldsPayload): bool
    {
        return $fieldKey !== ''
            && ! str_starts_with($fieldKey, '_')
            && array_key_exists($fieldKey, $fieldsPayload)
            && $this->isWritableDialogDictionaryField($fieldKey);
    }

    protected function isWritableDialogDictionaryField(string $fieldKey): bool
    {
        $this->editableDialogFieldKeys ??= FieldDictionaryField::query()
            ->where('entity', FieldDictionaryField::ENTITY_DIALOG)
            ->where('manual_write_access', FieldDictionaryField::MANUAL_WRITE_ACCESS_EDITABLE)
            ->pluck('field_key')
            ->mapWithKeys(fn (string $key): array => [$key => true])
            ->all();

        return isset($this->editableDialogFieldKeys[$fieldKey]);
    }

    protected function normalizeEditedDialogFieldValue(string $value, mixed $previousValue): mixed
    {
        $trimmed = trim($value);

        if (is_int($previousValue) && preg_match('/^-?\d+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (is_float($previousValue) && is_numeric($trimmed)) {
            return (float) $trimmed;
        }

        if (is_bool($previousValue)) {
            return match (mb_strtolower($trimmed)) {
                '1', 'true', 'yes', 'да' => true,
                '0', 'false', 'no', 'нет' => false,
                default => $value,
            };
        }

        return $value;
    }

    protected function formatDialogFieldEditableValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded !== false ? $encoded : '';
    }

    /**
     * @return array{value: string, type: string, is_truncated: bool}
     */
    protected function formatDialogFieldValue(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [
                'value' => '—',
                'type' => 'empty',
                'is_truncated' => false,
            ];
        }

        if (is_bool($value)) {
            return [
                'value' => $value ? 'Да' : 'Нет',
                'type' => 'scalar',
                'is_truncated' => false,
            ];
        }

        if (is_scalar($value)) {
            return $this->truncateDialogFieldValue((string) $value, 'scalar');
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $this->truncateDialogFieldValue(
            $encoded !== false ? $encoded : 'Неподдерживаемое значение',
            'json',
        );
    }

    /**
     * @return array{value: string, type: string, is_truncated: bool}
     */
    protected function truncateDialogFieldValue(string $value, string $type): array
    {
        $limit = 500;
        $isTruncated = mb_strlen($value) > $limit;

        return [
            'value' => $isTruncated ? mb_substr($value, 0, $limit).'…' : $value,
            'type' => $type,
            'is_truncated' => $isTruncated,
        ];
    }

    protected function formatDialogTimestamp(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->format('d.m.Y H:i')
            : '—';
    }

    protected function formatDialogPreview(mixed $value): ?string
    {
        $preview = trim((string) $value);

        if ($preview === '') {
            return null;
        }

        return mb_strlen($preview) > 140
            ? mb_substr($preview, 0, 140).'…'
            : $preview;
    }

    protected function formatDialogMessagePreview(Dialog $dialog, string $messageIdColumn, mixed $fallbackPreview): ?string
    {
        $messageId = $dialog->getAttribute($messageIdColumn);

        if (filled($messageId)) {
            $message = Message::query()->find((int) $messageId);

            if ($message instanceof Message) {
                $feed = app(BuildConversationFeedViewDataAction::class)->handle(new Collection([$message]));
                $displayText = trim((string) data_get($feed, '0.display_text', ''));

                if ($displayText !== '') {
                    return $this->formatDialogPreview($displayText);
                }
            }
        }

        return $this->formatDialogPreview($fallbackPreview);
    }

    protected function formatDialogSnapshotLine(string $timestamp, ?string $preview): string
    {
        return filled($preview)
            ? $timestamp.' · '.$preview
            : $timestamp;
    }

    protected function appendOutboundMessageToConversation(Message $message): void
    {
        $message->loadMissing(['channel', 'dialog.channel', 'sentByUser']);

        $this->appendConversationMessages(collect([$message]));
    }

    protected function refreshDialogRecord(): void
    {
        /** @var Dialog $dialog */
        $dialog = DialogResource::getEloquentQuery()->findOrFail($this->getRecord()->getKey());

        $this->record = $dialog;
    }

    protected function syncDialogInboxStatusSelection(): void
    {
        $this->dialogInboxStatusSelection = $this->resolveDialogInboxStatus($this->getRecord())->code;
    }

    protected function syncDialogStageSelection(): void
    {
        $this->dialogStageSelection = $this->resolveEffectiveDialogStage($this->getRecord());
    }

    protected function resolveEffectiveDialogStage(Dialog $dialog): string
    {
        return app(DialogStageCatalog::class)->keyForDialog($dialog)
            ?? app(ResolveDialogStageAction::class)->handle($dialog);
    }

    /**
     * @return array{appended_count:int,updated_count:int}
     */
    protected function appendLatestConversationMessages(): array
    {
        if ($this->conversationMessages === []) {
            $page = app(LoadDialogMessagesPageAction::class)->handle(
                $this->getRecord(),
                null,
                self::INITIAL_CONVERSATION_MESSAGE_LIMIT,
            );

            if ($page->messages->isEmpty()) {
                $this->hasMoreOlderMessages = false;
                $this->nextOlderCursor = null;
                $this->latestKnownMessageId = null;

                return [
                    'appended_count' => 0,
                    'updated_count' => 0,
                ];
            }

            $this->conversationMessages = app(BuildConversationFeedViewDataAction::class)->handle($page->messages);
            $this->hasMoreOlderMessages = $page->hasMoreOlderMessages;
            $this->nextOlderCursor = $page->nextOlderCursor;
            $this->latestKnownMessageId = $this->resolveLatestKnownMessageId($page->messages);

            return [
                'appended_count' => count($this->conversationMessages),
                'updated_count' => 0,
            ];
        }

        $updatedCount = $this->refreshVisibleConversationMessages();

        $messages = app(LoadDialogMessagesPageAction::class)->loadMessagesAddedAfterId(
            $this->getRecord(),
            $this->latestKnownMessageId,
            self::LIVE_REFRESH_MESSAGE_LIMIT,
        );

        return [
            'appended_count' => $this->appendConversationMessages($messages),
            'updated_count' => $updatedCount,
        ];
    }

    protected function refreshVisibleConversationMessages(): int
    {
        $messageIds = collect($this->conversationMessages)
            ->flatMap(fn (array $message): array => $this->conversationItemMessageIds($message))
            ->filter()
            ->unique()
            ->values();

        if ($messageIds->isEmpty()) {
            return 0;
        }

        $messageIds = $messageIds
            ->reverse()
            ->take(self::LIVE_REFRESH_MESSAGE_LIMIT)
            ->reverse()
            ->values();

        $messages = Message::query()
            ->where('dialog_id', $this->getRecord()->id)
            ->whereIn('id', $messageIds->all())
            ->with(['channel', 'dialog.channel', 'sentByUser'])
            ->get();

        if ($messages->isEmpty()) {
            return 0;
        }

        $refreshedViewData = app(BuildConversationFeedViewDataAction::class)->handle($messages);

        if ($refreshedViewData === []) {
            return 0;
        }

        $nextConversationMessages = $this->replaceConversationItemsByKey($this->conversationMessages, $refreshedViewData);
        $nextConversationMessages = $this->sortConversationMessages($nextConversationMessages);

        if ($nextConversationMessages === $this->conversationMessages) {
            return 0;
        }

        $this->conversationMessages = $nextConversationMessages;

        return count($refreshedViewData);
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function appendConversationMessages(Collection $messages): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        $existingMessageIds = $this->existingConversationMessageIds();

        $newMessages = $messages
            ->filter(fn (Message $message): bool => ! $existingMessageIds->has($message->id))
            ->values();

        if ($newMessages->isEmpty()) {
            $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($messages) ?? 0) ?: null;

            return 0;
        }

        $newMessageViewData = app(BuildConversationFeedViewDataAction::class)->handle($newMessages);

        if ($newMessageViewData === []) {
            return 0;
        }

        $this->conversationMessages = [
            ...$this->conversationMessages,
            ...$newMessageViewData,
        ];
        $this->conversationMessages = $this->replaceConversationItemsByKey($this->conversationMessages, $newMessageViewData);
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
        $this->latestKnownMessageId = max($this->latestKnownMessageId ?? 0, $this->resolveLatestKnownMessageId($newMessages) ?? 0) ?: null;

        return count($newMessageViewData);
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     */
    protected function prependConversationMessageViewData(array $messages): void
    {
        if ($messages === []) {
            return;
        }

        $existingMessageIds = $this->existingConversationMessageIds();
        $existingItemKeys = collect($this->conversationMessages)
            ->map(fn (array $message): string => $this->conversationItemKey($message))
            ->flip();

        $olderMessageViewData = collect($messages)
            ->filter(function (array $message) use ($existingMessageIds, $existingItemKeys): bool {
                if ($existingItemKeys->has($this->conversationItemKey($message))) {
                    return true;
                }

                return collect($this->conversationItemMessageIds($message))
                    ->doesntContain(fn (int $id): bool => $existingMessageIds->has($id));
            })
            ->values()
            ->all();

        if ($olderMessageViewData === []) {
            return;
        }

        $this->conversationMessages = [
            ...$olderMessageViewData,
            ...$this->conversationMessages,
        ];
        $this->conversationMessages = $this->replaceConversationItemsByKey($this->conversationMessages, $olderMessageViewData);
        $this->conversationMessages = $this->sortConversationMessages($this->conversationMessages);
    }

    protected function existingConversationMessageIds(): Collection
    {
        return collect($this->conversationMessages)
            ->flatMap(fn (array $message): array => $this->conversationItemMessageIds($message))
            ->filter()
            ->flip();
    }

    /**
     * @param  array<string, mixed>  $message
     * @return list<int>
     */
    protected function conversationItemMessageIds(array $message): array
    {
        $ids = $message['message_ids'] ?? [$message['id'] ?? null];

        return collect(is_array($ids) ? $ids : [$ids])
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    protected function conversationItemKey(array $message): string
    {
        return (string) ($message['item_key'] ?? 'message:'.($message['id'] ?? 'unknown'));
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @param  list<array<string, mixed>>  $replacementMessages
     * @return list<array<string, mixed>>
     */
    protected function replaceConversationItemsByKey(array $messages, array $replacementMessages): array
    {
        if ($replacementMessages === []) {
            return $messages;
        }

        $replacementByKey = collect($replacementMessages)
            ->keyBy(fn (array $message): string => $this->conversationItemKey($message));

        return collect($messages)
            ->reverse()
            ->unique(fn (array $message): string => $this->conversationItemKey($message))
            ->reverse()
            ->map(function (array $message) use ($replacementByKey): array {
                return $replacementByKey->get($this->conversationItemKey($message), $message);
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $messages
     * @return list<array<string, mixed>>
     */
    protected function sortConversationMessages(array $messages): array
    {
        usort($messages, function (array $left, array $right): int {
            return strcmp((string) ($left['sort_key'] ?? ''), (string) ($right['sort_key'] ?? ''));
        });

        return array_values($messages);
    }

    protected function syncNextOlderCursorToVisibleConversationStart(): void
    {
        if (! $this->hasMoreOlderMessages) {
            return;
        }

        $oldestVisibleMessage = $this->conversationMessages[0] ?? null;

        if (
            ! is_array($oldestVisibleMessage)
            || blank($oldestVisibleMessage['sort_at_iso'] ?? null)
            || blank($oldestVisibleMessage['id'] ?? null)
        ) {
            return;
        }

        $this->nextOlderCursor = [
            'sort_at' => (string) $oldestVisibleMessage['sort_at_iso'],
            'id' => (int) $oldestVisibleMessage['id'],
        ];
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function resolveLatestKnownMessageId(Collection $messages): ?int
    {
        $latestMessageId = $messages->max('id');

        return is_numeric($latestMessageId)
            ? (int) $latestMessageId
            : null;
    }

    protected function getContactViewUrl(): string
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return ContactResource::getUrl('index');
        }

        return ContactResource::getUrl('view', ['record' => $contact]);
    }

    /**
     * @return array{
     *     entry_point: string,
     *     back_url: string,
     *     back_label: string,
     *     items: list<array{label: string, url: string|null, is_current: bool}>
     * }
     */
    protected function getDialogBreadcrumbsViewData(): array
    {
        $dialog = $this->getRecord();
        $contact = $dialog->contact;
        $contactLabel = $contact instanceof Contact
            ? app(ResolveContactDisplayNameAction::class)->handle($contact, $dialog)
            : 'Контакт';
        $dialogsBackUrl = $this->resolveDialogsBackUrl();

        if ($dialogsBackUrl !== null) {
            return [
                'entry_point' => 'dialogs',
                'back_url' => $dialogsBackUrl,
                'back_label' => 'Вернуться в диалоги',
                'items' => [
                    [
                        'label' => 'Диалоги',
                        'url' => $dialogsBackUrl,
                        'is_current' => false,
                    ],
                    [
                        'label' => 'Диалог #'.$dialog->id,
                        'url' => null,
                        'is_current' => true,
                    ],
                ],
            ];
        }

        return [
            'entry_point' => 'contact',
            'back_url' => $this->getContactViewUrl(),
            'back_label' => 'Вернуться к контакту',
            'items' => [
                [
                    'label' => 'Контакты',
                    'url' => ContactResource::getUrl('index'),
                    'is_current' => false,
                ],
                [
                    'label' => $contactLabel,
                    'url' => $this->getContactViewUrl(),
                    'is_current' => false,
                ],
                [
                    'label' => 'Диалог · '.$this->formatChannelLabel($dialog->channel, 'Неизвестный канал'),
                    'url' => null,
                    'is_current' => true,
                ],
            ],
        ];
    }

    protected function resolveDialogsBackUrl(): ?string
    {
        return $this->dialogsBackUrl
            ?? $this->resolveDialogsBackUrlFromValue(request()->query('back_to'));
    }

    protected function resolveDialogsBackUrlFromValue(mixed $backTo): ?string
    {
        if (! is_string($backTo) || $backTo === '') {
            return null;
        }

        $dialogsPaths = collect([
            DialogResource::getUrl('index'),
            DialogResource::getUrl('kanban'),
        ])
            ->map(static fn (string $url): string => parse_url($url, PHP_URL_PATH) ?: $url)
            ->filter()
            ->unique()
            ->values();
        $backToPath = parse_url($backTo, PHP_URL_PATH) ?: $backTo;

        if (! $dialogsPaths->contains($backToPath)) {
            return null;
        }

        $backToQuery = parse_url($backTo, PHP_URL_QUERY);
        $safeBackTo = $backToPath.(is_string($backToQuery) && $backToQuery !== '' ? '?'.$backToQuery : '');

        return url($safeBackTo);
    }

    protected function resolveCurrentEmployee(): User
    {
        /** @var User|null $employee */
        $employee = auth()->user();

        if (! $employee instanceof User) {
            throw new RuntimeException('Не удалось определить текущего сотрудника.');
        }

        return $employee;
    }

    protected function resolveReplyOwnerContact(): ?Contact
    {
        $contact = $this->getRecord()->contact;

        if (! $contact instanceof Contact) {
            return null;
        }

        return app(ResolveRootContactAction::class)->handle($contact);
    }

    protected function canCurrentUserReplyToDialog(): bool
    {
        if (! $this->canCurrentUserManageDialogReplies()) {
            return false;
        }

        return $this->getDialogRouteBlockedReason() === null;
    }

    protected function canCurrentUserManageDialogReplies(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canReplyInDialogs();
    }

    protected function canCurrentUserManageDialogStages(): bool
    {
        return $this->canCurrentUserManageDialogReplies();
    }

    protected function canCurrentUserManageDialogContactOwnership(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canManageContactOwnership();
    }

    protected function getDialogReplyBlockedReason(): ?string
    {
        return $this->getDialogRouteBlockedReason();
    }

    protected function getDialogRouteBlockedReason(): ?string
    {
        return $this->resolveDialogRouteStatus($this->getRecord())->blockedReason;
    }

    protected function getDialogStageBlockedReason(): ?string
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function getDialogInboxStatusOptions(DialogInboxStatusData $status): array
    {
        return match ($status->code) {
            DialogInboxStatusData::CODE_REQUIRES_REPLY => [
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
            ],
            DialogInboxStatusData::CODE_NOT_REQUIRED => [
                DialogInboxStatusData::CODE_NOT_REQUIRED => 'Не требует ответа',
                DialogInboxStatusData::CODE_REQUIRES_REPLY => 'Требует ответа',
            ],
            default => [
                DialogInboxStatusData::CODE_NO_NEW => 'Нет новых',
            ],
        };
    }

    protected function formatContactPhonesLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return '—';
        }

        $phoneNumbers = ($contact->relationLoaded('phoneNumbers')
            ? $contact->phoneNumbers
            : $contact->phoneNumbers()->get())
            ->map(fn (ContactPhoneNumber $phoneNumber): ?string => $phoneNumber->phone_raw ?: $phoneNumber->phone_normalized)
            ->filter(fn (?string $phone): bool => filled($phone))
            ->unique()
            ->values();

        return $phoneNumbers->isEmpty()
            ? '—'
            : $phoneNumbers->implode(', ');
    }

    protected function formatAssignedUserLabel(?Contact $contact): string
    {
        if (! $contact instanceof Contact) {
            return 'Свободен';
        }

        return $contact->assignedUser instanceof User
            ? $contact->assignedUser->getFilamentName()
            : 'Свободен';
    }

    /**
     * @return array<string, string>
     */
    protected function getAssignableUserOptions(): array
    {
        return User::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $user): bool => $user->canBeAssignedToContacts())
            ->mapWithKeys(fn (User $user): array => [
                (string) $user->id => $user->getFilamentName(),
            ])
            ->all();
    }

    protected function formatChannelLabel(?Channel $channel, string $fallback = '—'): string
    {
        if ($channel === null) {
            return $fallback;
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: $fallback;
    }

    protected function resolveDialogRouteStatus(Dialog $dialog): DialogRouteStatusData
    {
        return app(ResolveDialogRouteStatusAction::class)->handle($dialog);
    }

    protected function resolveDialogInboxStatus(Dialog $dialog): DialogInboxStatusData
    {
        return app(ResolveDialogInboxStatusAction::class)->handle($dialog);
    }

    protected function formatDialogPhoneLabel(Dialog $dialog): string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return 'Телефон в этом канале не подтвержден';
    }

    protected function formatDialogExternalUsernameLabel(Dialog $dialog): string
    {
        $username = trim((string) $dialog->currentContactIdentity?->external_username);

        if ($username === '') {
            return '—';
        }

        return '@'.ltrim($username, '@');
    }

    protected function formatDialogMessengerNameLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;

        if (filled($identity?->display_name)) {
            return trim((string) $identity->display_name);
        }

        if (filled($identity?->external_username)) {
            return '@'.ltrim((string) $identity->external_username, '@');
        }

        if (filled($identity?->external_user_id)) {
            return 'ID: '.$identity->external_user_id;
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return '—';
    }

    protected function formatDialogRouteIdentityLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;
        $parts = [];

        if (filled($identity?->external_user_id)) {
            $parts[] = 'ID: '.$identity->external_user_id;
        }

        if (filled($identity?->external_username)) {
            $parts[] = '@'.ltrim((string) $identity->external_username, '@');
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return 'Не задан';
    }

    protected function resolveDialogAvatarUrl(Dialog $dialog): ?string
    {
        $avatarPath = $dialog->currentContactIdentity?->avatar_path;

        if (! filled($avatarPath)) {
            return null;
        }

        $cacheKey = ((string) ($dialog->current_contact_identity_id ?? 'none')).':'.$avatarPath;

        if (array_key_exists($cacheKey, $this->dialogAvatarUrlCache)) {
            return $this->dialogAvatarUrlCache[$cacheKey];
        }

        try {
            $avatarStorage = app(ContactIdentityAvatarStorage::class);

            if ($avatarStorage->exists($avatarPath)) {
                return $this->dialogAvatarUrlCache[$cacheKey] = $avatarStorage->url($avatarPath);
            }
        } catch (Throwable) {
            // Fallback to the legacy disk when object storage is temporarily unavailable.
        }

        $legacyDisk = Storage::disk('public');

        if (! $legacyDisk->exists($avatarPath)) {
            return $this->dialogAvatarUrlCache[$cacheKey] = null;
        }

        return $this->dialogAvatarUrlCache[$cacheKey] = $legacyDisk->url($avatarPath);
    }

    protected function formatDialogAvatarFallbackLabel(Dialog $dialog): ?string
    {
        $identity = $dialog->currentContactIdentity;
        $candidate = null;

        if (filled($identity?->display_name)) {
            $candidate = trim((string) $identity->display_name);
        } elseif (filled($identity?->external_username)) {
            $candidate = ltrim((string) $identity->external_username, '@');
        }

        if (! filled($candidate)) {
            return null;
        }

        $parts = preg_split('/\s+/u', $candidate, -1, PREG_SPLIT_NO_EMPTY);

        if (is_array($parts) && count($parts) > 1) {
            $initials = collect($parts)
                ->take(2)
                ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
                ->implode('');

            return $initials !== '' ? $initials : null;
        }

        $initials = mb_strtoupper(mb_substr($candidate, 0, 2));

        return $initials !== '' ? $initials : null;
    }

    protected function formatPeerSyncTimestamp(mixed $value): string
    {
        return $value instanceof Carbon
            ? $value->format('d.m.Y H:i')
            : '—';
    }
}
