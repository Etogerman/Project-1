<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Data\Bitrix24\Bitrix24RescueSyncDiagnosticData;
use App\Data\Bitrix24\Bitrix24RescueSyncQueueResultData;
use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\Concerns\InteractsWithContactWorkspace;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\User;
use App\Services\Bitrix24\BuildBitrix24CrmEntityUrlAction;
use App\Services\Bitrix24\DiagnoseBitrix24RescueSyncAction;
use App\Services\Bitrix24\QueueBitrix24RescueSyncAction;
use App\Services\Contacts\AddContactTimelineCommentAction;
use App\Services\Contacts\BuildContactCardViewLayoutAction;
use App\Services\Contacts\ContactCardViewBlockRegistry;
use App\Services\Contacts\SyncSystemContactCardViewAction;
use App\Services\Dialogs\DialogStageCatalog;
use App\Services\Dialogs\ResolveDialogStageAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Url;
use Throwable;

class ViewContact extends ViewRecord
{
    use InteractsWithContactWorkspace;

    public const TAB_GENERAL = 'general';

    public const TAB_DIALOGS = 'dialogs';

    public const TAB_BITRIX24 = 'bitrix24';

    public const TAB_DEDUP = 'dedup';

    public const TAB_SYSTEM_FIELDS = 'system_fields';

    public const TAB_HISTORY = 'history';

    public const TAB_DIAGNOSTICS = 'diagnostics';

    private const GENERAL_BLOCK_KEYS = [
        SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES,
        SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS,
        SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS,
    ];

    protected static string $resource = ContactResource::class;

    protected string $view = 'filament.contacts.pages.view-contact';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'tab', history: true, except: self::TAB_GENERAL)]
    public string $activeTab = self::TAB_GENERAL;

    public int $historyVisibleCount = 20;

    public string $historyCommentBody = '';

    /**
     * @var array<string, string>|null
     */
    protected ?array $contactFieldLabels = null;

    /**
     * @var array<string, array<string, string>>
     */
    protected array $contactOptionLabels = [];

    /**
     * @var array<string, array<string, string>>
     */
    protected array $dialogOptionLabels = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->activeTab = $this->normalizeTab((string) request()->query('tab', self::TAB_GENERAL));
        $this->historyVisibleCount = 20;

        $mountedRecord = $this->resolveWorkspaceContact();

        if ($mountedRecord instanceof Contact) {
            $this->fillProfileEditingState($mountedRecord);
            $this->inlineProfileDirty = false;
        }
    }

    public function loadMoreHistory(): void
    {
        $this->historyVisibleCount += 20;
    }

    public function addHistoryComment(): void
    {
        if ($this->abortIfContactHistoryCommentForbidden('Не удалось добавить комментарий')) {
            return;
        }

        $record = $this->resolveWorkspaceContactOrNotify('Не удалось добавить комментарий');

        if (! $record instanceof Contact) {
            return;
        }

        $this->validate([
            'historyCommentBody' => [
                'required',
                'string',
                'max:2000',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! is_string($value) || trim($value) === '') {
                        $fail('Введите комментарий.');
                    }
                },
            ],
        ], [
            'historyCommentBody.max' => 'Комментарий не должен быть длиннее 2000 символов.',
        ]);

        try {
            $employee = $this->resolveCurrentEmployee();

            app(AddContactTimelineCommentAction::class)->handle($record, $employee, $this->historyCommentBody);

            $this->historyCommentBody = '';
            $this->resetErrorBag('historyCommentBody');
            $this->replaceWorkspaceContactWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Комментарий добавлен')
                ->body('Комментарий сохранён во внутренней истории контакта.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось добавить комментарий')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function requestBitrix24RescueSync(): void
    {
        if (! $this->canCurrentEmployeeRequestBitrix24RescueSync()) {
            Notification::make()
                ->danger()
                ->title('Не удалось запустить синхронизацию')
                ->body('Для ручной синхронизации с Bitrix24 нужно право bitrix24.edit.')
                ->send();

            return;
        }

        $record = $this->resolveWorkspaceContactOrNotify('Не удалось запустить синхронизацию');

        if (! $record instanceof Contact) {
            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();
            $result = app(QueueBitrix24RescueSyncAction::class)->handle($record, $employee);
            $rootContact = Contact::query()->find($result->rootContactId);

            if ($rootContact instanceof Contact) {
                $this->replaceWorkspaceContactWithEffectiveContact($rootContact);
            } else {
                $this->syncWorkspaceContact($record);
            }

            $this->sendBitrix24RescueSyncNotification($result);
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось запустить синхронизацию')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function refreshBitrix24SyncState(): void
    {
        if ($this->activeTab !== self::TAB_BITRIX24) {
            return;
        }

        $record = $this->resolveWorkspaceContact();

        if (! $record instanceof Contact) {
            return;
        }

        $this->replaceWorkspaceContactWithEffectiveContact($record);
    }

    public function updatedActiveTab(string $value): void
    {
        $this->activeTab = $this->normalizeTab($value);
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = $this->normalizeTab($tab);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Контакт';
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

    /**
     * @return array<string>
     */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    public function getContentTabLabel(): ?string
    {
        return null;
    }

    public function getSubNavigation(): array
    {
        return [];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return $this->getPageRecordQuery()->findOrFail($key);
    }

    protected function getViewData(): array
    {
        $record = $this->getRecord();
        $isGeneralTab = $this->activeTab === self::TAB_GENERAL;
        $isDialogsTab = $this->activeTab === self::TAB_DIALOGS;
        $isBitrix24Tab = $this->activeTab === self::TAB_BITRIX24;
        $isDedupTab = $this->activeTab === self::TAB_DEDUP;
        $isSystemFieldsTab = $this->activeTab === self::TAB_SYSTEM_FIELDS;
        $isDiagnosticsTab = $this->activeTab === self::TAB_DIAGNOSTICS;
        $isHistoryTab = $this->activeTab === self::TAB_HISTORY;
        $isCustomTab = ! $this->isKnownContactTab($this->activeTab);
        $profileViewData = ContactResource::buildContactProfileViewData($record);
        $ownershipControls = $isGeneralTab || $isCustomTab
            ? ContactResource::buildOwnershipControlsViewData($record)
            : [];
        $showDedupStatus = ContactResource::shouldShowDedupStatusSection($record);
        $customTabSections = $isCustomTab
            ? $this->buildCustomTabSections(
                $record,
                $this->activeTab,
                (bool) ($profileViewData['canEditProfile'] ?? false),
                $profileViewData,
                $ownershipControls,
            )
            : [];
        $customBlockKeys = $this->collectCardViewBlockKeys($customTabSections);
        $dialogBlocks = $isDialogsTab
            ? $this->buildDialogBlocks($record)
            : [];
        $dialogsViewData = (($isDialogsTab && $this->dialogBlocksContainContactDialogs($dialogBlocks))
            || in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS, $customBlockKeys, true))
            ? ContactResource::buildDialogsViewData($record)
            : ['dialogs' => []];
        $historyBlocks = $isHistoryTab
            ? $this->buildHistoryBlocks($record)
            : [];
        $historyBlockVisible = $isHistoryTab && $this->historyBlocksContainContactHistory($historyBlocks);
        $customHistoryBlockVisible = in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY, $customBlockKeys, true)
            && $this->canViewHistoryTab();
        $generalBlocks = $isGeneralTab
            ? $this->buildGeneralBlocks($record)
            : [];
        $phoneBlockVisible = ($isGeneralTab && $this->generalBlocksContainBlock($generalBlocks, SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES))
            || in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES, $customBlockKeys, true);
        $emailBlockVisible = ($isGeneralTab && $this->generalBlocksContainBlock($generalBlocks, SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS))
            || in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS, $customBlockKeys, true);
        $tagBlockVisible = ($isGeneralTab && $this->generalBlocksContainBlock($generalBlocks, SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS))
            || in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS, $customBlockKeys, true);
        $tagsViewData = $tagBlockVisible
            ? ContactResource::buildTagsViewData($record)
            : [
                'tags' => [],
                'canManageTags' => false,
                'availableTags' => [],
            ];
        $phoneNumbersViewData = $phoneBlockVisible
            ? ContactResource::buildPhoneNumbersViewData($record)
            : [
                'phoneNumbers' => [],
                'canEditPhones' => false,
                'canDeletePhones' => false,
            ];
        $phoneNumbersViewData['sectionTitle'] = $this->contactFieldLabel('phones', 'Телефоны');
        $emailsViewData = $emailBlockVisible
            ? ContactResource::buildContactEmailsViewData($record)
            : [
                'emails' => [],
                'canEditEmails' => false,
                'canDeleteEmails' => false,
            ];
        $emailsViewData['sectionTitle'] = $this->contactFieldLabel('emails', 'Email');
        $dedupBlocks = $isDedupTab && $showDedupStatus
            ? $this->buildDedupBlocks($record)
            : [];
        $dedupBlockVisible = $isDedupTab && $showDedupStatus && $this->dedupBlocksContainContactDedup($dedupBlocks);
        $customDedupBlockVisible = $showDedupStatus
            && in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP, $customBlockKeys, true);
        $diagnosticsBlocks = $isDiagnosticsTab
            ? $this->buildDiagnosticsBlocks($record)
            : [];
        $diagnosticsBlockVisible = $isDiagnosticsTab && $this->diagnosticsBlocksContainContactDiagnostics($diagnosticsBlocks);
        $customDiagnosticsBlockVisible = ContactResource::canCurrentUserViewContactDiagnostics()
            && in_array(SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS, $customBlockKeys, true);
        $diagnosticsViewData = $diagnosticsBlockVisible || $customDiagnosticsBlockVisible
            ? ContactResource::buildDiagnosticsViewData($record)
            : null;
        $canAddHistoryComment = $this->canCurrentEmployeeAddContactHistoryComments();
        $generalCardSections = $isGeneralTab
            ? $this->buildGeneralCardSections($record, (bool) ($profileViewData['canEditProfile'] ?? false), $profileViewData, $ownershipControls)
            : [];

        return [
            'activeTab' => $this->activeTab,
            'isCustomTab' => $isCustomTab,
            'contactHeader' => $this->buildHeaderViewData($record, $profileViewData),
            'contactStats' => $isGeneralTab ? $this->buildStatsViewData($record) : [],
            'tabs' => $this->buildTabsViewData(),
            'showFieldKeys' => false,
            'generalCardSections' => $generalCardSections,
            'profileRows' => $generalCardSections['client_data']['rows'] ?? [],
            'locationRows' => $generalCardSections['location']['rows'] ?? [],
            'workRows' => $generalCardSections['work']['rows'] ?? [],
            'generalBlocks' => $generalBlocks,
            'bitrixSections' => $isBitrix24Tab ? $this->buildBitrixSections($record) : [],
            'bitrixRescueSyncViewData' => $isBitrix24Tab ? $this->buildBitrixRescueSyncViewData($record) : null,
            'systemFieldSections' => $isSystemFieldsTab ? $this->buildSystemFieldSections($record) : [],
            'customTabSections' => $customTabSections,
            'dialogBlocks' => $dialogBlocks,
            'dedupBlocks' => $dedupBlocks,
            'diagnosticsBlocks' => $diagnosticsBlocks,
            'historyBlocks' => $historyBlocks,
            'profileViewData' => $profileViewData,
            'ownershipControls' => $ownershipControls,
            'dedupStatusViewData' => ($dedupBlockVisible || $customDedupBlockVisible)
                ? ContactResource::buildDedupStatusViewData($record)
                : null,
            'diagnosticsViewData' => $diagnosticsViewData,
            'tagsViewData' => $tagsViewData,
            'phoneNumbersViewData' => $phoneNumbersViewData,
            'emailsViewData' => $emailsViewData,
            'dialogsViewData' => $dialogsViewData,
            'historyViewData' => ($historyBlockVisible || $customHistoryBlockVisible)
                ? ContactResource::buildHistoryTimelineViewData($record, $this->historyVisibleCount)
                : [
                    'items' => [],
                    'hasMore' => false,
                    'visibleCount' => 0,
                    'totalCount' => 0,
                ],
            'historyCommentViewData' => [
                'canAddComment' => ($historyBlockVisible || $customHistoryBlockVisible) && $canAddHistoryComment,
            ],
        ];
    }

    /**
     * @return array{
     *     canRequest:bool,
     *     isActionable:bool,
     *     statusLabel:string,
     *     statusTone:string,
     *     description:string,
     *     reasons:list<string>,
     *     errors:list<string>,
     *     shouldAutoRefresh:bool,
     *     compact:bool,
     *     rootContactId:int|null,
     *     requestedContactId:int
     * }
     */
    protected function buildBitrixRescueSyncViewData(Contact $record): array
    {
        $canRequest = $this->canCurrentEmployeeRequestBitrix24RescueSync();

        try {
            $diagnostics = app(DiagnoseBitrix24RescueSyncAction::class)->handle($record);
            $status = $this->formatBitrixRescueSyncDiagnosticStatus($diagnostics);

            return [
                'canRequest' => $canRequest,
                'isActionable' => $canRequest && ($diagnostics->canQueueContact || $diagnostics->canQueueDeal || $diagnostics->canQueueHistory),
                'statusLabel' => $status['label'],
                'statusTone' => $status['tone'],
                'description' => $status['description'],
                'reasons' => $this->formatBitrixRescueSyncReasons($diagnostics->reasons),
                'errors' => $this->formatBitrixRescueSyncErrors($diagnostics),
                'shouldAutoRefresh' => $this->shouldAutoRefreshBitrix24SyncState($diagnostics),
                'compact' => $this->shouldRenderCompactBitrix24RescueSyncState($diagnostics),
                'rootContactId' => $diagnostics->rootContactId,
                'requestedContactId' => $diagnostics->requestedContactId,
            ];
        } catch (Throwable $throwable) {
            Log::warning('contact_bitrix_rescue_sync_diagnostics_failed', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return [
                'canRequest' => $canRequest,
                'isActionable' => false,
                'statusLabel' => 'Диагностика недоступна',
                'statusTone' => 'danger',
                'description' => 'Не удалось проверить состояние синхронизации.',
                'reasons' => [],
                'errors' => [],
                'shouldAutoRefresh' => false,
                'compact' => false,
                'rootContactId' => null,
                'requestedContactId' => (int) $record->id,
            ];
        }
    }

    protected function shouldAutoRefreshBitrix24SyncState(Bitrix24RescueSyncDiagnosticData $diagnostics): bool
    {
        return $diagnostics->contactPending
            || $diagnostics->dealPending
            || $diagnostics->historyPending;
    }

    protected function shouldRenderCompactBitrix24RescueSyncState(Bitrix24RescueSyncDiagnosticData $diagnostics): bool
    {
        return in_array('already_fully_synced', $diagnostics->reasons, true)
            && ! $diagnostics->canQueueContact
            && ! $diagnostics->canQueueDeal
            && ! $diagnostics->canQueueHistory
            && ! $diagnostics->contactPending
            && ! $diagnostics->dealPending
            && ! $diagnostics->historyPending
            && ! $diagnostics->needsManualReview;
    }

    /**
     * @return array{label:string,tone:string,description:string}
     */
    protected function formatBitrixRescueSyncDiagnosticStatus(Bitrix24RescueSyncDiagnosticData $diagnostics): array
    {
        if ($diagnostics->canQueueContact) {
            return [
                'label' => 'Можно синхронизировать контакт',
                'tone' => 'success',
                'description' => 'Контакт будет поставлен в очередь без продолжения диалога.',
            ];
        }

        if ($diagnostics->canQueueDeal || $diagnostics->canQueueHistory) {
            $parts = array_filter([
                $diagnostics->canQueueDeal ? 'сделка' : null,
                $diagnostics->canQueueHistory ? 'история' : null,
            ]);

            return [
                'label' => 'Можно досинхронизировать Bitrix24',
                'tone' => 'success',
                'description' => 'В очередь будет поставлено: '.implode(', ', $parts).'.',
            ];
        }

        if ($diagnostics->needsManualReview) {
            return [
                'label' => 'Нужна ручная проверка',
                'tone' => 'warning',
                'description' => 'Автоматический запуск остановлен до разбора состояния Bitrix24.',
            ];
        }

        if (! $diagnostics->ready) {
            return [
                'label' => 'Контакт не готов',
                'tone' => 'warning',
                'description' => 'Перед синхронизацией нужно закрыть обязательные данные контакта.',
            ];
        }

        if ($diagnostics->contactPending || $diagnostics->dealPending || $diagnostics->historyPending) {
            return [
                'label' => 'Синхронизация выполняется',
                'tone' => 'warning',
                'description' => 'ID и статусы обновятся здесь после завершения очереди.',
            ];
        }

        if (in_array('already_fully_synced', $diagnostics->reasons, true)) {
            return [
                'label' => 'Bitrix24 синхронизирован',
                'tone' => 'success',
                'description' => 'Дополнительный запуск сейчас не требуется.',
            ];
        }

        return [
            'label' => 'Нет доступного действия',
            'tone' => 'warning',
            'description' => 'Состояние не требует ручного запуска.',
        ];
    }

    /**
     * @param  list<string>  $reasons
     * @return list<string>
     */
    protected function formatBitrixRescueSyncReasons(array $reasons): array
    {
        return array_values(array_filter(array_map(
            fn (string $reason): ?string => $this->formatBitrixRescueSyncReason($reason),
            $reasons,
        )));
    }

    /**
     * @return list<string>
     */
    protected function formatBitrixRescueSyncErrors(Bitrix24RescueSyncDiagnosticData $diagnostics): array
    {
        $errors = [];

        if (filled($diagnostics->lastContactError)) {
            $errors[] = 'Контакт: '.$diagnostics->lastContactError;
        }

        if (filled($diagnostics->lastDealError)) {
            $errors[] = 'Сделка: '.$diagnostics->lastDealError;
        }

        if (filled($diagnostics->lastHistoryError)) {
            $errors[] = 'История: '.$diagnostics->lastHistoryError;
        }

        return $errors;
    }

    protected function formatBitrixRescueSyncReason(string $reason): ?string
    {
        return match ($reason) {
            'using_root_contact' => 'будет использован основной контакт',
            'data_collection_not_completed' => 'анкета не завершена',
            'missing_root_contact' => 'контакт не является основным',
            'missing_first_name' => 'нет имени',
            'missing_country' => 'нет страны',
            'missing_city' => 'нет города',
            'missing_age_range' => 'нет возраста',
            'missing_phone' => 'нет телефона',
            'missing_primary_identity' => 'нет канала связи',
            'contact_needs_manual_review' => 'контакт требует ручной проверки',
            'deal_needs_manual_review' => 'сделка требует ручной проверки',
            'contact_already_pending' => 'контакт уже в очереди',
            'deal_already_pending' => 'сделка уже в очереди',
            'history_already_pending' => 'история уже в очереди',
            'deals_sync_disabled' => 'синхронизация сделок отключена',
            'history_sync_disabled' => 'выгрузка истории отключена',
            'already_fully_synced' => 'всё уже синхронизировано',
            default => null,
        };
    }

    protected function canCurrentEmployeeRequestBitrix24RescueSync(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->hasRolePermission('bitrix24.edit');
    }

    protected function sendBitrix24RescueSyncNotification(Bitrix24RescueSyncQueueResultData $result): void
    {
        $notification = Notification::make()
            ->title($this->bitrix24RescueSyncNotificationTitle($result->status))
            ->body($this->bitrix24RescueSyncNotificationBody($result));

        match ($result->status) {
            'queued', 'synced' => $notification->success(),
            'needs_manual_review', 'not_ready', 'already_pending' => $notification->warning(),
            default => $notification->warning(),
        };

        $notification->send();
    }

    protected function bitrix24RescueSyncNotificationTitle(string $status): string
    {
        return match ($status) {
            'queued' => 'Синхронизация поставлена в очередь',
            'needs_manual_review' => 'Нужна ручная проверка',
            'not_ready' => 'Контакт не готов',
            'already_pending' => 'Задача уже в очереди',
            'synced' => 'Bitrix24 уже синхронизирован',
            default => 'Синхронизация не запущена',
        };
    }

    protected function bitrix24RescueSyncNotificationBody(Bitrix24RescueSyncQueueResultData $result): string
    {
        if ($result->status === 'queued') {
            $queuedParts = array_filter([
                $result->queuedContact ? 'контакт' : null,
                $result->queuedDeal ? 'сделка' : null,
                $result->queuedHistory ? 'история' : null,
            ]);

            return 'В очередь поставлено: '.implode(', ', $queuedParts).'. ID и статусы появятся на вкладке Bitrix24 после выполнения очереди.';
        }

        $reasons = $this->formatBitrixRescueSyncReasons($result->skippedReasons);

        if ($reasons !== []) {
            return 'Причины: '.implode(', ', array_slice($reasons, 0, 4)).'.';
        }

        return match ($result->status) {
            'synced' => 'Дополнительный запуск сейчас не требуется.',
            'already_pending' => 'Дождитесь завершения текущей задачи.',
            default => 'Проверьте состояние контакта на вкладке Bitrix24.',
        };
    }

    /**
     * @return array<string, array{dataRole:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function buildGeneralCardSections(Contact $record, bool $canEditProfile, array $profileViewData, array $ownershipControls): array
    {
        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->general();

            if (! is_array($layout)) {
                return $this->buildFallbackGeneralCardSections($record, $canEditProfile, $profileViewData, $ownershipControls);
            }

            $sections = [];
            $rowsByKey = $this->buildGeneralCardRowsByKey($record, $canEditProfile, $profileViewData, $ownershipControls);

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $rows = [];

                foreach (($section['fields'] ?? []) as $fieldKey) {
                    $row = $rowsByKey[(string) $fieldKey] ?? null;

                    if ($row === null) {
                        Log::warning('contact_card_view_unknown_field_key', [
                            'contact_id' => $record->id,
                            'field_key' => $fieldKey,
                            'section_key' => $sectionKey,
                        ]);

                        continue;
                    }

                    $rows[] = $row;
                }

                $sections[$sectionKey] = [
                    'dataRole' => $this->contactCardSectionDataRole($sectionKey),
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'rows' => $rows,
                ];
            }

            foreach (['client_data', 'location', 'work'] as $requiredSectionKey) {
                if (! array_key_exists($requiredSectionKey, $sections)) {
                    return $this->buildFallbackGeneralCardSections($record, $canEditProfile, $profileViewData, $ownershipControls);
                }
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $this->buildFallbackGeneralCardSections($record, $canEditProfile, $profileViewData, $ownershipControls);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildGeneralCardRowsByKey(Contact $record, bool $canEditProfile, array $profileViewData, array $ownershipControls): array
    {
        $rows = [
            ...$this->buildProfileRows($record, $canEditProfile, $profileViewData),
            ...$this->buildLocationRows($record, $canEditProfile, $profileViewData),
            ...$this->buildWorkRows($record, $ownershipControls),
        ];

        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [(string) ($row['key'] ?? '') => $row])
            ->filter(fn (array $row, string $key): bool => $key !== '')
            ->all();
    }

    /**
     * @return array<string, array{dataRole:string,title:string,rows:list<array<string, mixed>>}>
     */
    protected function buildFallbackGeneralCardSections(Contact $record, bool $canEditProfile, array $profileViewData, array $ownershipControls): array
    {
        return [
            'client_data' => [
                'dataRole' => 'contact-section-client-data',
                'title' => 'Данные клиента',
                'rows' => $this->buildProfileRows($record, $canEditProfile, $profileViewData),
            ],
            'location' => [
                'dataRole' => 'contact-section-location',
                'title' => 'Локация',
                'rows' => $this->buildLocationRows($record, $canEditProfile, $profileViewData),
            ],
            'work' => [
                'dataRole' => 'contact-section-work',
                'title' => 'Работа с контактом',
                'rows' => $this->buildWorkRows($record, $ownershipControls),
            ],
        ];
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildGeneralBlocks(Contact $record): array
    {
        $fallback = $this->buildFallbackGeneralBlocks();

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->generalBlocks();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $blocks = [];

                foreach (($section['blocks'] ?? []) as $blockKey) {
                    $blockKey = (string) $blockKey;

                    if (in_array($blockKey, self::GENERAL_BLOCK_KEYS, true)) {
                        $blocks[] = $blockKey;

                        continue;
                    }

                    Log::warning('contact_card_view_unknown_general_block_key', [
                        'contact_id' => $record->id,
                        'block_key' => $blockKey,
                        'section_key' => $sectionKey,
                    ]);
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'blocks' => $blocks,
                ];
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_general_blocks_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildFallbackGeneralBlocks(): array
    {
        return [
            [
                'section_key' => 'contact_phones',
                'title' => 'Телефоны',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_PHONES,
                ],
            ],
            [
                'section_key' => 'contact_emails',
                'title' => 'Email',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_EMAILS,
                ],
            ],
            [
                'section_key' => 'contact_tags',
                'title' => 'Теги',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_TAGS,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{section_key:string,title:string,blocks:list<string>}>  $generalBlocks
     */
    protected function generalBlocksContainBlock(array $generalBlocks, string $expectedBlockKey): bool
    {
        foreach ($generalBlocks as $section) {
            foreach (($section['blocks'] ?? []) as $blockKey) {
                if ($blockKey === $expectedBlockKey) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{section_key:string,title:string,rows:list<array<string, mixed>>,blocks:list<string>}>
     */
    protected function buildCustomTabSections(
        Contact $record,
        string $tabKey,
        bool $canEditProfile,
        array $profileViewData,
        array $ownershipControls,
    ): array {
        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->itemsForTab($tabKey);

            if (! is_array($layout)) {
                return [];
            }

            $rowsByKey = $this->buildAllContactRowsByKey($record, $canEditProfile, $profileViewData, $ownershipControls);
            $blockRegistry = app(ContactCardViewBlockRegistry::class);
            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $rows = [];
                $blocks = [];

                foreach (($section['items'] ?? []) as $item) {
                    $itemKey = (string) ($item['item_key'] ?? '');
                    $itemType = (string) ($item['item_type'] ?? '');

                    if ($itemKey === '') {
                        continue;
                    }

                    if ($itemType === 'field') {
                        $complexBlockKey = (string) ($item['renderer_block_key'] ?? '');

                        if ($complexBlockKey !== '') {
                            $blocks[] = $complexBlockKey;

                            continue;
                        }

                        $row = $rowsByKey[$itemKey] ?? null;

                        if ($row === null) {
                            Log::warning('contact_card_view_unknown_custom_field_key', [
                                'contact_id' => $record->id,
                                'field_key' => $itemKey,
                                'section_key' => $sectionKey,
                                'tab_key' => $tabKey,
                            ]);

                            continue;
                        }

                        $rows[] = $row;

                        continue;
                    }

                    if ($itemType === 'block') {
                        if (! $blockRegistry->contains($itemKey)) {
                            Log::warning('contact_card_view_unknown_custom_block_key', [
                                'contact_id' => $record->id,
                                'block_key' => $itemKey,
                                'section_key' => $sectionKey,
                                'tab_key' => $tabKey,
                            ]);

                            continue;
                        }

                        $blocks[] = $itemKey;
                    }
                }

                if ($rows === [] && $blocks === []) {
                    continue;
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'rows' => $rows,
                    'blocks' => $blocks,
                ];
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_custom_card_view_fallback_used', [
                'contact_id' => $record->id,
                'tab_key' => $tabKey,
                'error' => $throwable->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * @param  list<array{blocks:list<string>}>  $sections
     * @return list<string>
     */
    protected function collectCardViewBlockKeys(array $sections): array
    {
        return collect($sections)
            ->flatMap(fn (array $section): array => $section['blocks'] ?? [])
            ->filter(fn (string $blockKey): bool => $blockKey !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function buildAllContactRowsByKey(Contact $record, bool $canEditProfile, array $profileViewData, array $ownershipControls): array
    {
        $rows = [
            ...array_values($this->buildGeneralCardRowsByKey($record, $canEditProfile, $profileViewData, $ownershipControls)),
            ...collect($this->buildFallbackBitrixSections($record))->flatMap(fn (array $section): array => $section['rows'])->all(),
            ...collect($this->buildFallbackSystemFieldSections($record))->flatMap(fn (array $section): array => $section['rows'])->all(),
        ];

        return collect($rows)
            ->mapWithKeys(fn (array $row): array => [(string) ($row['key'] ?? '') => $row])
            ->filter(fn (array $row, string $key): bool => $key !== '')
            ->all();
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildDialogBlocks(Contact $record): array
    {
        $fallback = $this->buildFallbackDialogBlocks();

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->dialogs();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $blocks = [];

                foreach (($section['blocks'] ?? []) as $blockKey) {
                    $blockKey = (string) $blockKey;

                    if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS) {
                        $blocks[] = $blockKey;

                        continue;
                    }

                    Log::warning('contact_card_view_unknown_dialog_block_key', [
                        'contact_id' => $record->id,
                        'block_key' => $blockKey,
                        'section_key' => $sectionKey,
                    ]);
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'blocks' => $blocks,
                ];
            }

            if ($sections === []) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_dialogs_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildFallbackDialogBlocks(): array
    {
        return [
            [
                'section_key' => 'contact_dialogs',
                'title' => 'Диалоги',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{section_key:string,title:string,blocks:list<string>}>  $dialogBlocks
     */
    protected function dialogBlocksContainContactDialogs(array $dialogBlocks): bool
    {
        foreach ($dialogBlocks as $section) {
            foreach (($section['blocks'] ?? []) as $blockKey) {
                if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DIALOGS) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildHistoryBlocks(Contact $record): array
    {
        $fallback = $this->buildFallbackHistoryBlocks();

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->history();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $blocks = [];

                foreach (($section['blocks'] ?? []) as $blockKey) {
                    $blockKey = (string) $blockKey;

                    if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY) {
                        $blocks[] = $blockKey;

                        continue;
                    }

                    Log::warning('contact_card_view_unknown_history_block_key', [
                        'contact_id' => $record->id,
                        'block_key' => $blockKey,
                        'section_key' => $sectionKey,
                    ]);
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'blocks' => $blocks,
                ];
            }

            if ($sections === []) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_history_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildFallbackHistoryBlocks(): array
    {
        return [
            [
                'section_key' => 'contact_history',
                'title' => 'История',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{section_key:string,title:string,blocks:list<string>}>  $historyBlocks
     */
    protected function historyBlocksContainContactHistory(array $historyBlocks): bool
    {
        foreach ($historyBlocks as $section) {
            foreach (($section['blocks'] ?? []) as $blockKey) {
                if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_HISTORY) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildDedupBlocks(Contact $record): array
    {
        $fallback = $this->buildFallbackDedupBlocks();

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->dedup();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $blocks = [];

                foreach (($section['blocks'] ?? []) as $blockKey) {
                    $blockKey = (string) $blockKey;

                    if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP) {
                        $blocks[] = $blockKey;

                        continue;
                    }

                    Log::warning('contact_card_view_unknown_dedup_block_key', [
                        'contact_id' => $record->id,
                        'block_key' => $blockKey,
                        'section_key' => $sectionKey,
                    ]);
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'blocks' => $blocks,
                ];
            }

            if ($sections === []) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_dedup_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildFallbackDedupBlocks(): array
    {
        return [
            [
                'section_key' => 'contact_dedup',
                'title' => 'Склейки',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{section_key:string,title:string,blocks:list<string>}>  $dedupBlocks
     */
    protected function dedupBlocksContainContactDedup(array $dedupBlocks): bool
    {
        foreach ($dedupBlocks as $section) {
            foreach (($section['blocks'] ?? []) as $blockKey) {
                if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DEDUP) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildDiagnosticsBlocks(Contact $record): array
    {
        $fallback = $this->buildFallbackDiagnosticsBlocks();

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->diagnostics();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $blocks = [];

                foreach (($section['blocks'] ?? []) as $blockKey) {
                    $blockKey = (string) $blockKey;

                    if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS) {
                        $blocks[] = $blockKey;

                        continue;
                    }

                    Log::warning('contact_card_view_unknown_diagnostics_block_key', [
                        'contact_id' => $record->id,
                        'block_key' => $blockKey,
                        'section_key' => $sectionKey,
                    ]);
                }

                $sections[] = [
                    'section_key' => $sectionKey,
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'blocks' => $blocks,
                ];
            }

            if ($sections === []) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_diagnostics_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{section_key:string,title:string,blocks:list<string>}>
     */
    protected function buildFallbackDiagnosticsBlocks(): array
    {
        return [
            [
                'section_key' => 'contact_diagnostics',
                'title' => 'Диагностика',
                'blocks' => [
                    SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS,
                ],
            ],
        ];
    }

    /**
     * @param  list<array{section_key:string,title:string,blocks:list<string>}>  $diagnosticsBlocks
     */
    protected function diagnosticsBlocksContainContactDiagnostics(array $diagnosticsBlocks): bool
    {
        foreach ($diagnosticsBlocks as $section) {
            foreach (($section['blocks'] ?? []) as $blockKey) {
                if ($blockKey === SyncSystemContactCardViewAction::BLOCK_CONTACT_DIAGNOSTICS) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveWorkspaceContact(): ?Contact
    {
        $record = $this->getRecord();

        return $record instanceof Contact ? $record : null;
    }

    protected function syncWorkspaceContact(Contact $contact): void
    {
        $freshContact = $this->getPageRecordQuery()->findOrFail($contact->getKey());

        if ((int) $this->getRecord()->getKey() !== (int) $freshContact->getKey()) {
            $this->redirect(ContactResource::getUrl('view', ['record' => $freshContact, 'tab' => $this->activeTab]));

            return;
        }

        $this->record = $freshContact;
    }

    /**
     * @return Builder<Contact>
     */
    protected function getPageRecordQuery(): Builder
    {
        return Contact::query()
            ->with([
                'assignedUser',
                'mergedInto',
            ])
            ->withCount('mergedChildren');
    }

    protected function clearWorkspaceContactAfterDelete(): void
    {
        $this->redirect(ContactResource::getUrl('index'));
    }

    /**
     * @return array{
     *     backUrl:string,
     *     id:int,
     *     initial:string,
     *     meta:string,
     *     title:string,
     *     mergedRootLabel:?string,
     *     mergedRootUrl:?string,
     *     canEditProfile:bool
     * }
     */
    protected function buildHeaderViewData(Contact $record, array $profileViewData): array
    {
        $record->loadMissing('mergedInto');
        $mergedInto = $record->mergedInto;

        return [
            'backUrl' => ContactResource::getUrl('index'),
            'id' => (int) $record->id,
            'initial' => $this->resolveAvatarInitial($record),
            'meta' => sprintf(
                'ID %d · Создан %s · Обновлён %s',
                (int) $record->id,
                $this->formatDate($record->created_at),
                $this->formatDate($record->updated_at),
            ),
            'title' => $this->resolveHeadingLabel($record),
            'mergedRootLabel' => $mergedInto instanceof Contact
                ? sprintf('#%d %s', $mergedInto->id, $mergedInto->display_name)
                : null,
            'mergedRootUrl' => $mergedInto instanceof Contact
                ? ContactResource::getUrl('view', ['record' => $mergedInto])
                : null,
            'canEditProfile' => (bool) ($profileViewData['canEditProfile'] ?? false),
        ];
    }

    /**
     * @return list<array{label:string,value:string,meta:string}>
     */
    protected function buildStatsViewData(Contact $record): array
    {
        $dialogCount = $record->dialogs()->count();
        $workingStageIds = collect(Dialog::workingStages())
            ->map(fn (string $stage): ?int => app(DialogStageCatalog::class)->stageIdForKey($stage))
            ->filter()
            ->values()
            ->all();
        $workingDialogCount = $record->dialogs()
            ->where(function (Builder $query) use ($workingStageIds): void {
                $query->whereIn('stage', Dialog::workingStages());

                if ($workingStageIds !== []) {
                    $query->orWhereIn('stage_id', $workingStageIds);
                }
            })
            ->count();
        $closedDialogCount = $record->dialogs()
            ->where('bitrix24_live_status', Dialog::BITRIX24_LIVE_STATUS_CLOSED)
            ->count();
        $latestDialog = $record->dialogs()
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        $lastContactValue = $latestDialog instanceof Dialog
            ? $this->formatDateTime($latestDialog->last_message_at)
            : '—';
        $latestDialogStage = $latestDialog instanceof Dialog
            ? app(ResolveDialogStageAction::class)->handle($latestDialog)
            : null;

        $lastContactMeta = $latestDialog instanceof Dialog
            ? trim(sprintf(
                '%s · %s',
                $this->dialogOptionLabel('stage', $latestDialogStage, Dialog::stageLabel($latestDialogStage)),
                $latestDialog->last_inbound_at !== null ? 'входящее' : 'нет входящих'
            ))
            : 'Диалогов пока нет';

        return [
            [
                'label' => 'Диалоги',
                'value' => (string) $dialogCount,
                'meta' => sprintf(
                    '%s · %s',
                    $this->pluralizeRussian($workingDialogCount, 'рабочий', 'рабочих', 'рабочих'),
                    $this->pluralizeRussian($closedDialogCount, 'закрытый', 'закрытых', 'закрытых'),
                ),
            ],
            [
                'label' => 'Последний контакт',
                'value' => $lastContactValue,
                'meta' => $lastContactMeta,
            ],
            [
                'label' => 'Bitrix24',
                'value' => $this->formatBitrixSyncStatus($record->bitrix24_sync_status),
                'meta' => filled($record->bitrix24_contact_id)
                    ? 'Контакт #'.$record->bitrix24_contact_id
                    : 'Нет связанного контакта',
            ],
        ];
    }

    protected function pluralizeRussian(int $count, string $one, string $few, string $many): string
    {
        $absolute = abs($count);
        $lastTwo = $absolute % 100;
        $last = $absolute % 10;

        $word = ($lastTwo >= 11 && $lastTwo <= 14)
            ? $many
            : match (true) {
                $last === 1 => $one,
                $last >= 2 && $last <= 4 => $few,
                default => $many,
            };

        return $count.' '.$word;
    }

    /**
     * @return list<array{key:string,label:string,url:string,isActive:bool}>
     */
    protected function buildTabsViewData(): array
    {
        return collect($this->availableContactTabs())
            ->map(fn (array $tab): array => $this->makeTab($tab['key'], $tab['label']))
            ->values()
            ->all();
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function availableContactTabs(): array
    {
        $record = $this->resolveWorkspaceContact();
        $fallbackTabs = $this->fallbackContactTabs();
        $fallbackAllowedTabs = collect($fallbackTabs)
            ->filter(fn (array $tab): bool => $this->contactTabAllowed($tab['key'], $record))
            ->values()
            ->all();

        try {
            $layoutTabs = app(BuildContactCardViewLayoutAction::class)->tabs();
        } catch (Throwable $throwable) {
            Log::warning('contact_card_view_tabs_fallback_used', [
                'contact_id' => $record?->id,
                'error' => $throwable->getMessage(),
            ]);

            $layoutTabs = null;
        }

        $sourceTabs = is_array($layoutTabs)
            ? collect($layoutTabs)
                ->map(fn (array $tab): array => [
                    'key' => (string) ($tab['tab_key'] ?? ''),
                    'label' => (string) ($tab['title'] ?? ''),
                ])
                ->filter(fn (array $tab): bool => $tab['key'] !== '')
                ->values()
                ->all()
            : $fallbackTabs;

        $fallbackLabels = collect($fallbackTabs)
            ->mapWithKeys(fn (array $tab): array => [$tab['key'] => $tab['label']]);

        $tabs = [];

        foreach ($sourceTabs as $tab) {
            $key = (string) ($tab['key'] ?? '');

            if (! $this->contactTabAllowed($key, $record)) {
                continue;
            }

            $tabs[] = [
                'key' => $key,
                'label' => filled($tab['label'] ?? null)
                    ? (string) $tab['label']
                    : (string) ($fallbackLabels[$key] ?? $key),
            ];
        }

        return $tabs !== []
            ? $tabs
            : $fallbackAllowedTabs;
    }

    /**
     * @return list<array{key:string,label:string}>
     */
    protected function fallbackContactTabs(): array
    {
        return [
            ['key' => self::TAB_GENERAL, 'label' => 'Общее'],
            ['key' => self::TAB_DIALOGS, 'label' => 'Диалоги'],
            ['key' => self::TAB_BITRIX24, 'label' => 'Битрикс24'],
            ['key' => self::TAB_HISTORY, 'label' => 'История'],
            ['key' => self::TAB_DEDUP, 'label' => 'Склейки'],
            ['key' => self::TAB_SYSTEM_FIELDS, 'label' => 'Системные поля'],
            ['key' => self::TAB_DIAGNOSTICS, 'label' => 'Диагностика'],
        ];
    }

    protected function contactTabAllowed(string $tabKey, ?Contact $record): bool
    {
        return match ($tabKey) {
            self::TAB_GENERAL,
            self::TAB_DIALOGS,
            self::TAB_BITRIX24,
            self::TAB_SYSTEM_FIELDS => true,
            self::TAB_DEDUP => $record instanceof Contact && ContactResource::shouldShowDedupStatusSection($record),
            self::TAB_HISTORY => $this->canViewHistoryTab(),
            self::TAB_DIAGNOSTICS => ContactResource::canCurrentUserViewContactDiagnostics(),
            default => ! $this->isKnownContactTab($tabKey),
        };
    }

    protected function isKnownContactTab(string $tabKey): bool
    {
        return collect($this->fallbackContactTabs())
            ->pluck('key')
            ->contains($tabKey);
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildProfileRows(Contact $record, bool $canEditProfile, array $profileViewData): array
    {
        $profileAction = $canEditProfile
            ? $this->makeAction(
                method: 'openEditProfileDialog',
                target: 'openEditProfileDialog,saveMountedContactProfile',
                label: 'Редактировать поле'
            )
            : null;

        return [
            $this->makeRow($this->contactFieldLabel('first_name', 'Имя'), 'first_name', $record->first_name ?? '—', $profileAction, edit: $this->makeInlineEdit('editingFirstName', 'text', $canEditProfile)),
            $this->makeRow($this->contactFieldLabel('first_name_source', 'Откуда знаем'), 'first_name_source', $this->resolveFirstNameSourceValue($record)),
            $this->makeRow($this->contactFieldLabel('last_name', 'Фамилия'), 'last_name', $record->last_name ?? '—', $profileAction, edit: $this->makeInlineEdit('editingLastName', 'text', $canEditProfile)),
            $this->makeRow($this->contactFieldLabel('first_name_resolution_method', 'Обработали'), 'first_name_resolution_method', $this->resolveFirstNameResolutionMethodValue($record)),
            $this->makeRow($this->contactFieldLabel('gender', 'Пол'), 'gender', $this->resolveGenderValue($record), $profileAction, edit: $this->makeInlineEdit('editingGender', 'select', $canEditProfile, $profileViewData['genderOptions'] ?? [])),
            $this->makeRow($this->contactFieldLabel('gender_source', 'Откуда знаем'), 'gender_source', $this->contactOptionLabel('gender_source', $record->gender_source, Contact::formatGenderSource($record->gender_source))),
            $this->makeRow($this->contactFieldLabel('birth_date', 'Дата рождения'), 'birth_date', $this->formatDate($record->birth_date), $profileAction, edit: $this->makeInlineEdit('editingBirthDate', 'date', $canEditProfile)),
            $this->makeRow('Возраст', 'effective_age_years', $record->effective_age_years !== null ? (string) $record->effective_age_years : '—'),
            $this->makeRow($this->contactFieldLabel('age_range', 'Возрастной диапазон'), 'age_range', $this->contactOptionLabel('age_range', $record->age_range, Contact::formatAgeRange($record->age_range)), $profileAction, edit: $this->makeInlineEdit('editingAgeRange', 'select', $canEditProfile, $profileViewData['ageRangeOptions'] ?? []), wide: true),
        ];
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildLocationRows(Contact $record, bool $canEditProfile, array $profileViewData): array
    {
        $locationAction = $canEditProfile
            ? $this->makeAction(
                method: 'openEditProfileDialog',
                target: 'openEditProfileDialog,saveMountedContactProfile',
                label: 'Редактировать локацию'
            )
            : null;

        return [
            $this->makeRow($this->contactFieldLabel('country', 'Страна'), 'country', $record->country ?? '—', $locationAction, edit: $this->makeInlineEdit('editingCountry', 'text', $canEditProfile)),
            $this->makeRow($this->contactFieldLabel('city', 'Город'), 'city', $record->city ?? '—', $locationAction, edit: $this->makeInlineEdit('editingCity', 'text', $canEditProfile)),
            $this->makeRow($this->contactFieldLabel('region', 'Регион'), 'region', $record->region ?? '—', $locationAction, edit: $this->makeInlineEdit('editingRegion', 'select', $canEditProfile, $profileViewData['regionOptions'] ?? [])),
            $this->makeRow($this->contactFieldLabel('region_status', 'Статус региона'), 'region_status', Contact::formatRegionStatus($record->region_status)),
            $this->makeRow($this->contactFieldLabel('region_source', 'Источник региона'), 'region_source', $this->formatRegionSource($record->region_source)),
            $this->makeRow('Кандидаты региона', 'pending_region_candidates', $this->formatArrayValue($record->pending_region_candidates)),
            $this->makeRow($this->contactFieldLabel('distance_to_moscow_km', 'Расстояние до Москвы'), 'distance_to_moscow_km', $record->distance_to_moscow_km !== null ? $record->distance_to_moscow_km.' км' : '—'),
            $this->makeRow($this->contactFieldLabel('distance_to_moscow_status', 'Статус расчёта'), 'distance_to_moscow_status', Contact::formatDistanceToMoscowStatus($record->distance_to_moscow_status)),
            $this->makeRow($this->contactFieldLabel('distance_to_moscow_calculated_at', 'Расстояние рассчитано'), 'distance_to_moscow_calculated_at', $this->formatDateTime($record->distance_to_moscow_calculated_at)),
        ];
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildWorkRows(Contact $record, array $ownershipControls): array
    {
        return [
            $this->makeRow(
                $this->contactFieldLabel('assigned_user_id', 'Ответственный'),
                'assigned_user_id',
                (string) ($ownershipControls['assignedUserLabel'] ?? ContactResource::formatAssignedUserLabel($record)),
                ($ownershipControls['canManageOwnership'] ?? false)
                    ? $this->makeAction(
                        method: 'openAssignContactDialog',
                        target: 'openAssignContactDialog,saveMountedContactAssignee',
                        label: 'Изменить ответственного'
                    )
                    : null,
            ),
            $this->makeRow(
                $this->contactFieldLabel('is_auto_reply_enabled', 'Автоответы'),
                'is_auto_reply_enabled',
                (string) ($ownershipControls['autoReplyStatusLabel'] ?? ($record->isAutoReplyEnabled() ? 'Включены' : 'Отключены')),
                $this->buildAutoReplyAction($ownershipControls),
            ),
            $this->makeRow(
                $this->contactFieldLabel('has_blocked_bot_dialog', 'Заблокирован клиентом'),
                'has_blocked_bot_dialog',
                $record->dialogs()
                    ->where('bot_subscription_status', Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER)
                    ->exists() ? 'Да' : 'Нет',
            ),
        ];
    }

    protected function contactCardSectionDataRole(string $sectionKey): string
    {
        return match ($sectionKey) {
            'client_data' => 'contact-section-client-data',
            'location' => 'contact-section-location',
            'work' => 'contact-section-work',
            default => 'contact-section-'.$sectionKey,
        };
    }

    protected function contactFieldLabel(string $fieldKey, string $fallback): string
    {
        $this->contactFieldLabels ??= FieldDictionaryField::labelsFor(FieldDictionaryField::ENTITY_CONTACT);

        return $this->contactFieldLabels[$fieldKey] ?? $fallback;
    }

    protected function contactOptionLabel(string $fieldKey, mixed $value, string $fallback): string
    {
        $this->contactOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_CONTACT, $fieldKey);

        return FieldDictionaryField::optionLabelFrom($this->contactOptionLabels[$fieldKey], $value, $fallback);
    }

    protected function dialogOptionLabel(string $fieldKey, mixed $value, string $fallback): string
    {
        $this->dialogOptionLabels[$fieldKey] ??= FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, $fieldKey);

        return FieldDictionaryField::optionLabelFrom($this->dialogOptionLabels[$fieldKey], $value, $fallback);
    }

    /**
     * @return list<array{title:string,subtitle:string,rows:list<array<string, mixed>>}>
     */
    protected function buildBitrixSections(Contact $record): array
    {
        $fallback = $this->buildFallbackBitrixSections($record);

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->bitrix24();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $rows = [];

                foreach (($section['fields'] ?? []) as $fieldKey) {
                    $row = $this->buildBitrixRowForField((string) $fieldKey, $record);

                    if ($row === null) {
                        Log::warning('contact_card_view_unknown_bitrix_field_key', [
                            'contact_id' => $record->id,
                            'field_key' => $fieldKey,
                            'section_key' => $sectionKey,
                        ]);

                        continue;
                    }

                    $rows[] = $row;
                }

                $rows = $this->injectBitrix24CrmEntityUrlRows($rows, $record);

                $sections[] = [
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'subtitle' => $this->contactBitrixSectionSubtitle($sectionKey),
                    'rows' => $rows,
                ];
            }

            if (count($sections) < 3) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_bitrix_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{title:string,subtitle:string,rows:list<array<string, mixed>>}>
     */
    protected function buildFallbackBitrixSections(Contact $record): array
    {
        return [
            [
                'title' => 'Контакт в Bitrix24',
                'subtitle' => 'Состояние синхронизации карточки контакта в CRM.',
                'rows' => array_values(array_filter([
                    $this->makeRow(
                        'ID контакта в Bitrix24',
                        'bitrix24_contact_id',
                        $record->bitrix24_contact_id ?? '—',
                    ),
                    $this->makeBitrix24CrmEntityUrlRow(
                        'Ссылка на карточку контакта',
                        'bitrix24_contact_url',
                        BuildBitrix24CrmEntityUrlAction::ENTITY_CONTACT,
                        $record->bitrix24_contact_id,
                        'Открыть контакт в Bitrix24',
                        'contact-bitrix24-contact-link',
                    ),
                    $this->makeRow('Статус синхронизации контакта', 'bitrix24_sync_status', $this->formatBitrixSyncStatus($record->bitrix24_sync_status)),
                    $this->makeRow('Контакт синхронизирован', 'bitrix24_last_synced_at', $this->formatDateTime($record->bitrix24_last_synced_at)),
                    $this->makeRow('Контакт привязан к Bitrix24', 'bitrix24_linked_at', $this->formatDateTime($record->bitrix24_linked_at)),
                    $this->makeRow('Синхронизация контакта в очереди', 'bitrix24_sync_pending', $this->formatBoolean($record->bitrix24_sync_pending)),
                    $this->makeRow('Fingerprint синхронизации', 'bitrix24_sync_fingerprint', $record->bitrix24_sync_fingerprint ?? '—'),
                ])),
            ],
            [
                'title' => 'Сделка в Bitrix24',
                'subtitle' => 'Состояние привязки и синхронизации связанной сделки.',
                'rows' => array_values(array_filter([
                    $this->makeRow(
                        'ID сделки в Bitrix24',
                        'bitrix24_deal_id',
                        $record->bitrix24_deal_id ?? '—',
                    ),
                    $this->makeBitrix24CrmEntityUrlRow(
                        'Ссылка на сделку',
                        'bitrix24_deal_url',
                        BuildBitrix24CrmEntityUrlAction::ENTITY_DEAL,
                        $record->bitrix24_deal_id,
                        'Открыть сделку в Bitrix24',
                        'contact-bitrix24-deal-link',
                    ),
                    $this->makeRow('Статус синхронизации сделки', 'bitrix24_deal_sync_status', $this->formatBitrixDealStatus($record->bitrix24_deal_sync_status)),
                    $this->makeRow('Сделка синхронизирована', 'bitrix24_deal_last_synced_at', $this->formatDateTime($record->bitrix24_deal_last_synced_at)),
                    $this->makeRow('Сделка привязана к Bitrix24', 'bitrix24_deal_linked_at', $this->formatDateTime($record->bitrix24_deal_linked_at)),
                    $this->makeRow('Синхронизация сделки в очереди', 'bitrix24_deal_sync_pending', $this->formatBoolean($record->bitrix24_deal_sync_pending)),
                ])),
            ],
            [
                'title' => 'История в Bitrix24',
                'subtitle' => 'Статус выгрузки истории переписки в CRM.',
                'rows' => [
                    $this->makeRow('Статус выгрузки истории', 'bitrix24_history_sync_status', $this->formatBitrixHistoryStatus($record->bitrix24_history_sync_status)),
                    $this->makeRow('История выгружена', 'bitrix24_history_last_synced_at', $this->formatDateTime($record->bitrix24_history_last_synced_at)),
                    $this->makeRow('Выгрузка истории в очереди', 'bitrix24_history_sync_pending', $this->formatBoolean($record->bitrix24_history_sync_pending)),
                ],
            ],
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    protected function buildBitrixRowForField(string $fieldKey, Contact $record): ?array
    {
        foreach ($this->buildFallbackBitrixSections($record) as $section) {
            foreach ($section['rows'] as $row) {
                if (($row['key'] ?? null) === $fieldKey) {
                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function injectBitrix24CrmEntityUrlRows(array $rows, Contact $record): array
    {
        $rows = $this->insertBitrix24CrmEntityUrlRowAfter(
            $rows,
            'bitrix24_contact_id',
            $this->makeBitrix24CrmEntityUrlRow(
                'Ссылка на карточку контакта',
                'bitrix24_contact_url',
                BuildBitrix24CrmEntityUrlAction::ENTITY_CONTACT,
                $record->bitrix24_contact_id,
                'Открыть контакт в Bitrix24',
                'contact-bitrix24-contact-link',
            ),
        );

        return $this->insertBitrix24CrmEntityUrlRowAfter(
            $rows,
            'bitrix24_deal_id',
            $this->makeBitrix24CrmEntityUrlRow(
                'Ссылка на сделку',
                'bitrix24_deal_url',
                BuildBitrix24CrmEntityUrlAction::ENTITY_DEAL,
                $record->bitrix24_deal_id,
                'Открыть сделку в Bitrix24',
                'contact-bitrix24-deal-link',
            ),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  ?array<string, mixed>  $urlRow
     * @return list<array<string, mixed>>
     */
    protected function insertBitrix24CrmEntityUrlRowAfter(array $rows, string $afterKey, ?array $urlRow): array
    {
        if ($urlRow === null) {
            return $rows;
        }

        $urlRowKey = (string) ($urlRow['key'] ?? '');
        $keys = array_map(static fn (array $row): string => (string) ($row['key'] ?? ''), $rows);

        if (! in_array($afterKey, $keys, true) || in_array($urlRowKey, $keys, true)) {
            return $rows;
        }

        $injectedRows = [];

        foreach ($rows as $row) {
            $injectedRows[] = $row;

            if ((string) ($row['key'] ?? '') === $afterKey) {
                $injectedRows[] = $urlRow;
            }
        }

        return $injectedRows;
    }

    /**
     * @return ?array<string, mixed>
     */
    protected function makeBitrix24CrmEntityUrlRow(
        string $label,
        string $key,
        string $entityType,
        mixed $entityId,
        string $linkTitle,
        string $dataRole,
    ): ?array {
        $link = $this->makeBitrix24CrmEntityLink($entityType, $entityId, $linkTitle, $dataRole);

        if ($link === null) {
            return null;
        }

        return $this->makeRow(
            $label,
            $key,
            $link['url'],
            link: array_merge($link, ['replaceValue' => true]),
        );
    }

    /**
     * @return ?array{url:string,label:string,title:string,dataRole:string}
     */
    protected function makeBitrix24CrmEntityLink(string $entityType, mixed $entityId, string $label, string $dataRole): ?array
    {
        $url = app(BuildBitrix24CrmEntityUrlAction::class)->handle($entityType, $entityId);

        if ($url === null) {
            return null;
        }

        return [
            'url' => $url,
            'label' => $url,
            'title' => $label,
            'dataRole' => $dataRole,
        ];
    }

    protected function contactBitrixSectionSubtitle(string $sectionKey): string
    {
        return match ($sectionKey) {
            'bitrix24_contact' => 'Состояние синхронизации карточки контакта в CRM.',
            'bitrix24_deal' => 'Состояние привязки и синхронизации связанной сделки.',
            'bitrix24_history' => 'Статус выгрузки истории переписки в CRM.',
            default => '',
        };
    }

    /**
     * @return list<array{title:string,subtitle:string,rows:list<array{label:string,key:string,value:string}>}>
     */
    protected function buildSystemFieldSections(Contact $record): array
    {
        $fallback = $this->buildFallbackSystemFieldSections($record);

        try {
            $layout = app(BuildContactCardViewLayoutAction::class)->systemFields();

            if (! is_array($layout)) {
                return $fallback;
            }

            $sections = [];

            foreach ($layout['sections'] as $section) {
                $sectionKey = (string) ($section['section_key'] ?? '');

                if ($sectionKey === '') {
                    continue;
                }

                $rows = [];

                foreach (($section['fields'] ?? []) as $fieldKey) {
                    $row = $this->buildSystemFieldRowForField((string) $fieldKey, $record);

                    if ($row === null) {
                        Log::warning('contact_card_view_unknown_system_field_key', [
                            'contact_id' => $record->id,
                            'field_key' => $fieldKey,
                            'section_key' => $sectionKey,
                        ]);

                        continue;
                    }

                    $rows[] = $row;
                }

                $sections[] = [
                    'title' => (string) ($section['title'] ?? $sectionKey),
                    'subtitle' => $this->contactSystemFieldSectionSubtitle($sectionKey),
                    'rows' => $rows,
                ];
            }

            if (count($sections) < 2) {
                return $fallback;
            }

            return $sections;
        } catch (Throwable $throwable) {
            Log::warning('contact_system_fields_card_view_fallback_used', [
                'contact_id' => $record->id,
                'error' => $throwable->getMessage(),
            ]);

            return $fallback;
        }
    }

    /**
     * @return list<array{title:string,subtitle:string,rows:list<array{label:string,key:string,value:string}>}>
     */
    protected function buildFallbackSystemFieldSections(Contact $record): array
    {
        $record->loadMissing('mergedInto');

        return [
            [
                'title' => 'Служебные поля контакта',
                'subtitle' => 'Идентификаторы и даты записи контакта.',
                'rows' => [
                    $this->makeRow($this->contactFieldLabel('id', 'ID'), 'id', (string) $record->id),
                    $this->makeRow($this->contactFieldLabel('created_at', 'Создан'), 'created_at', $this->formatDateTime($record->created_at)),
                    $this->makeRow($this->contactFieldLabel('updated_at', 'Обновлён'), 'updated_at', $this->formatDateTime($record->updated_at)),
                ],
            ],
            [
                'title' => 'Склейки и дубли',
                'subtitle' => 'Служебные поля дедупликации контакта.',
                'rows' => [
                    $this->makeRow($this->contactFieldLabel('duplicate_review_status', 'Статус проверки дубля'), 'duplicate_review_status', $this->formatSystemDuplicateReviewStatus($record->duplicate_review_status)),
                    $this->makeRow($this->contactFieldLabel('merged_into_contact_id', 'Основной контакт'), 'merged_into_contact_id', $record->mergedInto !== null ? sprintf('#%d %s', $record->mergedInto->id, $record->mergedInto->display_name) : '—'),
                    $this->makeRow($this->contactFieldLabel('merged_at', 'Склеен'), 'merged_at', $this->formatDateTime($record->merged_at)),
                    $this->makeRow($this->contactFieldLabel('merge_reason', 'Причина склейки'), 'merge_reason', $this->formatSystemMergeReason($record->merge_reason)),
                    $this->makeRow($this->contactFieldLabel('merge_trigger_phone', 'Триггерный телефон'), 'merge_trigger_phone', $record->merge_trigger_phone ?: '—'),
                ],
            ],
        ];
    }

    /**
     * @return ?array{label:string,key:string,value:string}
     */
    protected function buildSystemFieldRowForField(string $fieldKey, Contact $record): ?array
    {
        foreach ($this->buildFallbackSystemFieldSections($record) as $section) {
            foreach ($section['rows'] as $row) {
                if (($row['key'] ?? null) === $fieldKey) {
                    return $row;
                }
            }
        }

        return null;
    }

    protected function contactSystemFieldSectionSubtitle(string $sectionKey): string
    {
        return match ($sectionKey) {
            'system_identity' => 'Идентификаторы и даты записи контакта.',
            'system_dedup' => 'Служебные поля дедупликации контакта.',
            default => '',
        };
    }

    protected function formatSystemDuplicateReviewStatus(?string $value): string
    {
        return match ($value) {
            Contact::DUPLICATE_REVIEW_STATUS_PENDING => 'Нужна проверка',
            Contact::DUPLICATE_REVIEW_STATUS_RESOLVED => 'Разобрано',
            Contact::DUPLICATE_REVIEW_STATUS_NONE, null, '' => '—',
            default => (string) $value,
        };
    }

    protected function formatSystemMergeReason(?string $mergeReason): string
    {
        return match ($mergeReason) {
            'phone_exact_match' => 'Совпадение телефона',
            'cross_channel_identity_resolution' => 'Разрешение cross-channel identity ambiguity',
            null, '' => '—',
            default => $mergeReason,
        };
    }

    /**
     * @return array{key:string,label:string,url:string,isActive:bool}
     */
    protected function makeTab(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'url' => ContactResource::getUrl('view', ['record' => $this->getRecord(), 'tab' => $key]),
            'isActive' => $this->activeTab === $key,
        ];
    }

    /**
     * @return array{
     *     label:string,
     *     key:string,
     *     value:string,
     *     action:?array{method:string,target:string,label:string,icon:string},
     *     items:list<array{
     *         kind:string,
     *         label:string,
     *         meta:?string,
     *         tone:?string,
     *         editAction:?string,
     *         editTarget:?string,
     *         deleteAction:?string,
     *         deleteTarget:?string
     *     }>
     * }
     */
    protected function makeRow(
        string $label,
        string $key,
        string $value,
        ?array $action = null,
        array $items = [],
        ?array $edit = null,
        bool $wide = false,
        ?array $link = null,
    ): array {
        return [
            'label' => $label,
            'key' => $key,
            'value' => $value !== '' ? $value : '—',
            'action' => $action,
            'items' => $items,
            'edit' => $edit,
            'wide' => $wide,
            'link' => $link,
        ];
    }

    /**
     * @param  array<string, string>  $options
     * @return ?array{model:string,type:string,options:array<string, string>}
     */
    protected function makeInlineEdit(string $model, string $type, bool $enabled, array $options = []): ?array
    {
        if (! $enabled) {
            return null;
        }

        return [
            'model' => $model,
            'type' => $type,
            'options' => $options,
        ];
    }

    /**
     * @return array{method:string,target:string,label:string,icon:string}
     */
    protected function makeAction(
        string $method,
        string $target,
        string $label,
        string $icon = 'heroicon-m-pencil-square',
    ): array {
        return [
            'method' => $method,
            'target' => $target,
            'label' => $label,
            'icon' => $icon,
        ];
    }

    protected function normalizeTab(string $tab): string
    {
        $availableTabs = $this->availableContactTabs();
        $allowedTabs = collect($availableTabs)
            ->pluck('key')
            ->all();

        if (in_array($tab, $allowedTabs, true)) {
            return $tab;
        }

        return $availableTabs[0]['key'] ?? self::TAB_GENERAL;
    }

    protected function canViewHistoryTab(): bool
    {
        $record = $this->resolveWorkspaceContact();

        return ! ($record instanceof Contact && $record->isMerged());
    }

    protected function resolveHeadingLabel(Contact $record): string
    {
        $parts = array_values(array_filter([
            filled($record->last_name) ? trim((string) $record->last_name) : null,
            filled($record->first_name) ? trim((string) $record->first_name) : null,
        ]));

        if ($parts !== []) {
            return implode(' ', $parts);
        }

        $displayName = trim((string) $record->display_name);

        if ($displayName !== '') {
            return $displayName;
        }

        return 'Контакт #'.$record->id;
    }

    protected function resolveAvatarInitial(Contact $record): string
    {
        $label = $this->resolveHeadingLabel($record);
        $firstCharacter = mb_substr(trim($label), 0, 1);

        return $firstCharacter !== '' ? mb_strtoupper($firstCharacter) : 'A';
    }

    protected function resolveFirstNameSourceValue(Contact $record): string
    {
        if (! filled($record->first_name)) {
            return '—';
        }

        $label = Contact::formatFirstNameSourceBadgeLabel($record->first_name_source);

        if ($label === null) {
            return 'Источник не определён';
        }

        return $this->contactOptionLabel('first_name_source', $record->first_name_source, $label);
    }

    protected function resolveFirstNameResolutionMethodValue(Contact $record): string
    {
        if (! filled($record->first_name)) {
            return '—';
        }

        $label = Contact::formatFirstNameResolutionMethod($record->first_name_resolution_method);

        if ($label === null) {
            return 'Не указано';
        }

        return $this->contactOptionLabel('first_name_resolution_method', $record->first_name_resolution_method, $label);
    }

    protected function resolveGenderValue(Contact $record): string
    {
        if ($record->gender === 'unknown') {
            return '—';
        }

        return $this->contactOptionLabel('gender', $record->gender, Contact::formatGender($record->gender));
    }

    protected function formatDate(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('d.m.Y')
            : '—';
    }

    protected function formatDateTime(mixed $value): string
    {
        return $value instanceof \DateTimeInterface
            ? $value->format('d.m.Y H:i:s')
            : '—';
    }

    protected function formatBoolean(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        return $value ? 'Да' : 'Нет';
    }

    protected function formatArrayValue(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '—';
        }

        return collect($value)
            ->map(fn (mixed $item): string => is_scalar($item)
                ? (string) $item
                : (json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '—'))
            ->implode(', ');
    }

    protected function formatRegionSource(?string $value): string
    {
        return match ($value) {
            Contact::REGION_SOURCE_AI => 'ИИ',
            Contact::REGION_SOURCE_DICTIONARY => 'Справочник',
            Contact::REGION_SOURCE_CONFIRMED_BY_CONTACT => 'Подтверждён клиентом',
            Contact::REGION_SOURCE_MANUAL => 'Указан вручную',
            default => '—',
        };
    }

    protected function formatBitrixSyncStatus(?string $value): string
    {
        return match ($value) {
            Contact::BITRIX24_SYNC_STATUS_NOT_SYNCED => 'Не синхронизирован',
            Contact::BITRIX24_SYNC_STATUS_PENDING => 'В очереди',
            Contact::BITRIX24_SYNC_STATUS_SYNCED => 'Синхронизирован',
            Contact::BITRIX24_SYNC_STATUS_FAILED => 'Ошибка',
            Contact::BITRIX24_SYNC_STATUS_PENDING_REVIEW => 'Нужна проверка',
            default => '—',
        };
    }

    protected function formatBitrixDealStatus(?string $value): string
    {
        return match ($value) {
            Contact::BITRIX24_DEAL_SYNC_STATUS_NOT_SYNCED => 'Не синхронизирована',
            Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING => 'В очереди',
            Contact::BITRIX24_DEAL_SYNC_STATUS_SYNCED => 'Синхронизирована',
            Contact::BITRIX24_DEAL_SYNC_STATUS_FAILED => 'Ошибка',
            Contact::BITRIX24_DEAL_SYNC_STATUS_PENDING_REVIEW => 'Нужна проверка',
            default => '—',
        };
    }

    protected function formatBitrixHistoryStatus(?string $value): string
    {
        return match ($value) {
            Contact::BITRIX24_HISTORY_SYNC_STATUS_NOT_SYNCED => 'Не выгружена',
            Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING => 'В очереди',
            Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED => 'Выгружена',
            Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED => 'Ошибка',
            default => '—',
        };
    }

    protected function buildAutoReplyAction(array $ownershipControls): ?array
    {
        if (! ($ownershipControls['canManageAutoReply'] ?? false)) {
            return null;
        }

        $isEnabled = (bool) ($ownershipControls['autoReplyEnabled'] ?? false);

        return $this->makeAction(
            method: $isEnabled ? 'disableMountedContactAutoReply' : 'enableMountedContactAutoReply',
            target: 'disableMountedContactAutoReply,enableMountedContactAutoReply',
            label: $isEnabled ? 'Отключить автоответы' : 'Включить автоответы',
        );
    }

    /**
     * @param  list<array{id:int,name:string,slug:string,color:string,is_active:bool}>  $tags
     * @return list<array{
     *     kind:string,
     *     label:string,
     *     meta:?string,
     *     tone:?string,
     *     editAction:?string,
     *     editTarget:?string,
     *     deleteAction:?string,
     *     deleteTarget:?string
     * }>
     */
    protected function buildTagItems(array $tags, bool $canManageTags): array
    {
        return collect($tags)
            ->map(fn (array $tag): array => [
                'kind' => 'tag',
                'label' => (string) $tag['name'],
                'meta' => filled($tag['slug'] ?? null)
                    ? (string) $tag['slug'].(! ($tag['is_active'] ?? true) ? ' · отключён' : '')
                    : null,
                'tone' => (string) ($tag['color'] ?? 'gray'),
                'editAction' => null,
                'editTarget' => null,
                'deleteAction' => $canManageTags ? sprintf('removeMountedContactTag(%d)', (int) $tag['id']) : null,
                'deleteTarget' => $canManageTags ? 'removeMountedContactTag' : null,
            ])
            ->all();
    }

    /**
     * @param  list<array{id:int,phone:string,source:string,is_primary:bool}>  $phoneNumbers
     * @return list<array{
     *     kind:string,
     *     label:string,
     *     meta:?string,
     *     tone:?string,
     *     editAction:?string,
     *     editTarget:?string,
     *     deleteAction:?string,
     *     deleteTarget:?string
     * }>
     */
    protected function buildPhoneItems(array $phoneNumbers, bool $canEditPhones, bool $canDeletePhones): array
    {
        return collect($phoneNumbers)
            ->map(fn (array $phoneNumber): array => [
                'kind' => 'phone',
                'label' => (string) $phoneNumber['phone'],
                'meta' => trim(sprintf(
                    '%s%s',
                    (string) $phoneNumber['source'],
                    ($phoneNumber['is_primary'] ?? false) ? ' · Основной' : ''
                )),
                'tone' => ($phoneNumber['is_primary'] ?? false) ? 'success' : 'neutral',
                'editAction' => $canEditPhones ? sprintf('openEditPhoneDialog(%d)', (int) $phoneNumber['id']) : null,
                'editTarget' => $canEditPhones ? 'openEditPhoneDialog,saveMountedContactPhone' : null,
                'deleteAction' => $canDeletePhones ? sprintf('openDeletePhoneDialog(%d)', (int) $phoneNumber['id']) : null,
                'deleteTarget' => $canDeletePhones ? 'openDeletePhoneDialog,deleteMountedContactPhone' : null,
            ])
            ->all();
    }

    /**
     * @param  list<array{name:string}>  $tags
     */
    protected function formatTagSummary(array $tags): string
    {
        if ($tags === []) {
            return '—';
        }

        return collect($tags)
            ->pluck('name')
            ->filter(fn (mixed $name): bool => is_string($name) && $name !== '')
            ->implode(', ');
    }

    /**
     * @param  list<array{phone:string,is_primary:bool}>  $phoneNumbers
     */
    protected function formatPhoneSummary(array $phoneNumbers): string
    {
        if ($phoneNumbers === []) {
            return '—';
        }

        $primaryPhone = collect($phoneNumbers)->firstWhere('is_primary', true);

        if (is_array($primaryPhone)) {
            $remainingCount = count($phoneNumbers) - 1;

            return $remainingCount > 0
                ? sprintf('%s · ещё %d', $primaryPhone['phone'], $remainingCount)
                : (string) $primaryPhone['phone'];
        }

        return collect($phoneNumbers)
            ->pluck('phone')
            ->filter(fn (mixed $phone): bool => is_string($phone) && $phone !== '')
            ->implode(', ');
    }
}
