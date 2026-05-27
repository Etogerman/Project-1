<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\Concerns\InteractsWithContactWorkspace;
use App\Models\Contact;
use App\Models\Dialog;
use App\Services\Contacts\AddContactTimelineCommentAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use Throwable;

class ViewContact extends ViewRecord
{
    use InteractsWithContactWorkspace;

    public const TAB_GENERAL = 'general';

    public const TAB_DIALOGS = 'dialogs';

    public const TAB_BITRIX24 = 'bitrix24';

    public const TAB_HISTORY = 'history';

    public const TAB_DIAGNOSTICS = 'diagnostics';

    protected static string $resource = ContactResource::class;

    protected string $view = 'filament.contacts.pages.view-contact';

    protected Width|string|null $maxContentWidth = Width::Full;

    #[Url(as: 'tab', history: true, except: self::TAB_GENERAL)]
    public string $activeTab = self::TAB_GENERAL;

    public int $historyVisibleCount = 20;

    public string $historyCommentBody = '';

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
        $isDiagnosticsTab = $this->activeTab === self::TAB_DIAGNOSTICS;
        $isHistoryTab = $this->activeTab === self::TAB_HISTORY;
        $profileViewData = ContactResource::buildContactProfileViewData($record);
        $dialogsViewData = $isDialogsTab
            ? ContactResource::buildDialogsViewData($record)
            : ['dialogs' => []];
        $ownershipControls = $isGeneralTab
            ? ContactResource::buildOwnershipControlsViewData($record)
            : [];
        $tagsViewData = $isGeneralTab
            ? ContactResource::buildTagsViewData($record)
            : [
                'tags' => [],
                'canManageTags' => false,
                'availableTags' => [],
            ];
        $phoneNumbersViewData = $isGeneralTab
            ? ContactResource::buildPhoneNumbersViewData($record)
            : [
                'phoneNumbers' => [],
                'canEditPhones' => false,
                'canDeletePhones' => false,
            ];
        $showDedupStatus = $isGeneralTab && ContactResource::shouldShowDedupStatusSection($record);
        $diagnosticsViewData = $isDiagnosticsTab
            ? ContactResource::buildDiagnosticsViewData($record)
            : null;
        $canAddHistoryComment = $this->canCurrentEmployeeAddContactHistoryComments();

        return [
            'activeTab' => $this->activeTab,
            'contactHeader' => $this->buildHeaderViewData($record, $profileViewData),
            'contactStats' => $isGeneralTab ? $this->buildStatsViewData($record) : [],
            'tabs' => $this->buildTabsViewData(),
            'showFieldKeys' => false,
            'profileRows' => $isGeneralTab ? $this->buildProfileRows($record, (bool) ($profileViewData['canEditProfile'] ?? false), $profileViewData) : [],
            'locationRows' => $isGeneralTab ? $this->buildLocationRows($record, (bool) ($profileViewData['canEditProfile'] ?? false), $profileViewData) : [],
            'workRows' => $isGeneralTab ? $this->buildWorkRows($record, $ownershipControls, $tagsViewData, $phoneNumbersViewData) : [],
            'bitrixSections' => $isBitrix24Tab ? $this->buildBitrixSections($record) : [],
            'profileViewData' => $profileViewData,
            'ownershipControls' => $ownershipControls,
            'dedupStatusViewData' => $showDedupStatus
                ? ContactResource::buildDedupStatusViewData($record)
                : null,
            'diagnosticsViewData' => $diagnosticsViewData,
            'tagsViewData' => $tagsViewData,
            'phoneNumbersViewData' => $phoneNumbersViewData,
            'dialogsViewData' => $dialogsViewData,
            'historyViewData' => $isHistoryTab
                ? ContactResource::buildHistoryTimelineViewData($record, $this->historyVisibleCount)
                : [
                    'items' => [],
                    'hasMore' => false,
                    'visibleCount' => 0,
                    'totalCount' => 0,
                ],
            'historyCommentViewData' => [
                'canAddComment' => $canAddHistoryComment,
            ],
        ];
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
        $workingDialogCount = $record->dialogs()
            ->whereIn('stage', Dialog::workingStages())
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
        $lastContactMeta = $latestDialog instanceof Dialog
            ? trim(sprintf(
                '%s · %s',
                Dialog::stageLabel($latestDialog->stage),
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
        $tabs = [
            $this->makeTab(self::TAB_GENERAL, 'Общее'),
            $this->makeTab(self::TAB_DIALOGS, 'Диалоги'),
            $this->makeTab(self::TAB_BITRIX24, 'Битрикс24'),
        ];

        if ($this->canViewHistoryTab()) {
            $tabs[] = $this->makeTab(self::TAB_HISTORY, 'История');
        }

        if (ContactResource::canCurrentUserViewContactDiagnostics()) {
            $tabs[] = $this->makeTab(self::TAB_DIAGNOSTICS, 'Диагностика');
        }

        return $tabs;
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
            $this->makeRow('Имя', 'first_name', $record->first_name ?? '—', $profileAction, edit: $this->makeInlineEdit('editingFirstName', 'text', $canEditProfile)),
            $this->makeRow('Откуда знаем', 'first_name_source', $this->resolveFirstNameSourceValue($record)),
            $this->makeRow('Фамилия', 'last_name', $record->last_name ?? '—', $profileAction, edit: $this->makeInlineEdit('editingLastName', 'text', $canEditProfile)),
            $this->makeRow('Обработали', 'first_name_resolution_method', $this->resolveFirstNameResolutionMethodValue($record)),
            $this->makeRow('Пол', 'gender', $this->resolveGenderValue($record), $profileAction, edit: $this->makeInlineEdit('editingGender', 'select', $canEditProfile, $profileViewData['genderOptions'] ?? [])),
            $this->makeRow('Откуда знаем', 'gender_source', Contact::formatGenderSource($record->gender_source)),
            $this->makeRow('Дата рождения', 'birth_date', $this->formatDate($record->birth_date), $profileAction, edit: $this->makeInlineEdit('editingBirthDate', 'date', $canEditProfile)),
            $this->makeRow('Возраст', 'effective_age_years', $record->effective_age_years !== null ? (string) $record->effective_age_years : '—'),
            $this->makeRow('Возрастной диапазон', 'age_range', Contact::formatAgeRange($record->age_range), $profileAction, edit: $this->makeInlineEdit('editingAgeRange', 'select', $canEditProfile, $profileViewData['ageRangeOptions'] ?? []), wide: true),
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
            $this->makeRow('Страна', 'country', $record->country ?? '—', $locationAction, edit: $this->makeInlineEdit('editingCountry', 'text', $canEditProfile)),
            $this->makeRow('Город', 'city', $record->city ?? '—', $locationAction, edit: $this->makeInlineEdit('editingCity', 'text', $canEditProfile)),
            $this->makeRow('Регион', 'region', $record->region ?? '—', $locationAction, edit: $this->makeInlineEdit('editingRegion', 'select', $canEditProfile, $profileViewData['regionOptions'] ?? [])),
            $this->makeRow('Статус региона', 'region_status', Contact::formatRegionStatus($record->region_status)),
            $this->makeRow('Источник региона', 'region_source', $this->formatRegionSource($record->region_source)),
            $this->makeRow('Кандидаты региона', 'pending_region_candidates', $this->formatArrayValue($record->pending_region_candidates)),
            $this->makeRow('Расстояние до Москвы', 'distance_to_moscow_km', $record->distance_to_moscow_km !== null ? $record->distance_to_moscow_km.' км' : '—'),
            $this->makeRow('Статус расчёта', 'distance_to_moscow_status', Contact::formatDistanceToMoscowStatus($record->distance_to_moscow_status)),
            $this->makeRow('Расстояние рассчитано', 'distance_to_moscow_calculated_at', $this->formatDateTime($record->distance_to_moscow_calculated_at)),
        ];
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildWorkRows(Contact $record, array $ownershipControls, array $tagsViewData, array $phoneNumbersViewData): array
    {
        return [
            $this->makeRow(
                'Ответственный',
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
                'Автоответы',
                'is_auto_reply_enabled',
                (string) ($ownershipControls['autoReplyStatusLabel'] ?? ($record->isAutoReplyEnabled() ? 'Включены' : 'Отключены')),
                $this->buildAutoReplyAction($ownershipControls),
            ),
            $this->makeRow(
                'Категория автоответа',
                'auto_reply_category',
                filled($record->auto_reply_category) ? (string) $record->auto_reply_category : '—',
            ),
            $this->makeRow(
                'Заблокирован клиентом',
                'bot_subscription_status',
                $record->dialogs()
                    ->where('bot_subscription_status', Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER)
                    ->exists() ? 'Да' : 'Нет',
            ),
        ];
    }

    /**
     * @return list<array{title:string,subtitle:string,rows:list<array{label:string,key:string,value:string}>}>
     */
    protected function buildBitrixSections(Contact $record): array
    {
        return [
            [
                'title' => 'Контакт в Bitrix24',
                'subtitle' => 'Состояние синхронизации карточки контакта в CRM.',
                'rows' => [
                    $this->makeRow('ID контакта в Bitrix24', 'bitrix24_contact_id', $record->bitrix24_contact_id ?? '—'),
                    $this->makeRow('Статус синхронизации контакта', 'bitrix24_sync_status', $this->formatBitrixSyncStatus($record->bitrix24_sync_status)),
                    $this->makeRow('Контакт синхронизирован', 'bitrix24_last_synced_at', $this->formatDateTime($record->bitrix24_last_synced_at)),
                    $this->makeRow('Контакт привязан к Bitrix24', 'bitrix24_linked_at', $this->formatDateTime($record->bitrix24_linked_at)),
                    $this->makeRow('Синхронизация контакта в очереди', 'bitrix24_sync_pending', $this->formatBoolean($record->bitrix24_sync_pending)),
                    $this->makeRow('Fingerprint синхронизации', 'bitrix24_sync_fingerprint', $record->bitrix24_sync_fingerprint ?? '—'),
                ],
            ],
            [
                'title' => 'Сделка в Bitrix24',
                'subtitle' => 'Состояние привязки и синхронизации связанной сделки.',
                'rows' => [
                    $this->makeRow('ID сделки в Bitrix24', 'bitrix24_deal_id', $record->bitrix24_deal_id ?? '—'),
                    $this->makeRow('Статус синхронизации сделки', 'bitrix24_deal_sync_status', $this->formatBitrixDealStatus($record->bitrix24_deal_sync_status)),
                    $this->makeRow('Сделка синхронизирована', 'bitrix24_deal_last_synced_at', $this->formatDateTime($record->bitrix24_deal_last_synced_at)),
                    $this->makeRow('Сделка привязана к Bitrix24', 'bitrix24_deal_linked_at', $this->formatDateTime($record->bitrix24_deal_linked_at)),
                    $this->makeRow('Синхронизация сделки в очереди', 'bitrix24_deal_sync_pending', $this->formatBoolean($record->bitrix24_deal_sync_pending)),
                ],
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
    ): array {
        return [
            'label' => $label,
            'key' => $key,
            'value' => $value !== '' ? $value : '—',
            'action' => $action,
            'items' => $items,
            'edit' => $edit,
            'wide' => $wide,
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
        $allowedTabs = [
            self::TAB_GENERAL,
            self::TAB_DIALOGS,
            self::TAB_BITRIX24,
        ];

        if ($this->canViewHistoryTab()) {
            $allowedTabs[] = self::TAB_HISTORY;
        }

        if (ContactResource::canCurrentUserViewContactDiagnostics()) {
            $allowedTabs[] = self::TAB_DIAGNOSTICS;
        }

        return in_array($tab, $allowedTabs, true) ? $tab : self::TAB_GENERAL;
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

        return $label;
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

        return $label;
    }

    protected function resolveGenderValue(Contact $record): string
    {
        if ($record->gender === 'unknown') {
            return '—';
        }

        return Contact::formatGender($record->gender);
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
