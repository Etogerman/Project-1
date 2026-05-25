<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Filament\Resources\Contacts\Pages\Concerns\InteractsWithContactWorkspace;
use App\Models\Contact;
use App\Models\ContactQuestionnaireAnswer;
use App\Models\ContactQuestionnaireAttempt;
use App\Models\ContactQuestionnaireRun;
use App\Services\Contacts\AddContactTimelineCommentAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Url;
use RuntimeException;
use Throwable;

class ViewContact extends ViewRecord
{
    use InteractsWithContactWorkspace;

    public const TAB_GENERAL = 'general';

    public const TAB_QUESTIONNAIRES = 'questionnaires';

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
        return ContactResource::getTableRecordQuery(excludeMerged: false)->findOrFail($key);
    }

    protected function getViewData(): array
    {
        $record = $this->getRecord();
        $isGeneralTab = $this->activeTab === self::TAB_GENERAL;
        $isQuestionnairesTab = $this->activeTab === self::TAB_QUESTIONNAIRES;
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
        $collectorStatus = $isQuestionnairesTab
            ? ContactResource::buildCollectorStatusViewData($record)
            : [
                'canResume' => false,
                'canResumeAction' => false,
            ];
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
            'contactHeader' => $this->buildHeaderViewData($record, $profileViewData, $dialogsViewData),
            'tabs' => $this->buildTabsViewData(),
            'showFieldKeys' => false,
            'profileRows' => $isGeneralTab ? $this->buildProfileRows($record, (bool) ($profileViewData['canEditProfile'] ?? false)) : [],
            'locationRows' => $isGeneralTab ? $this->buildLocationRows($record, (bool) ($profileViewData['canEditProfile'] ?? false)) : [],
            'workRows' => $isGeneralTab ? $this->buildWorkRows($record, $ownershipControls, $tagsViewData, $phoneNumbersViewData) : [],
            'questionnaireRunsViewData' => $isQuestionnairesTab
                ? $this->buildQuestionnaireRunsViewData($record, (bool) ($profileViewData['canEditProfile'] ?? false))
                : [
                    'rows' => [],
                    'legacyRows' => [],
                ],
            'bitrixSections' => $isBitrix24Tab ? $this->buildBitrixSections($record) : [],
            'profileViewData' => $profileViewData,
            'ownershipControls' => $ownershipControls,
            'collectorStatus' => $collectorStatus,
            'dedupStatusViewData' => $showDedupStatus
                ? ContactResource::buildDedupStatusViewData($record)
                : null,
            'diagnosticsViewData' => $diagnosticsViewData,
            'questionnaireAction' => $collectorStatus['canResume'] && $collectorStatus['canResumeAction']
                ? $this->makeAction(
                    method: 'resumeMountedContactDataCollection',
                    target: 'resumeMountedContactDataCollection',
                    label: 'Возобновить анкету',
                    icon: 'heroicon-m-play'
                )
                : null,
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
        $freshContact = ContactResource::getTableRecordQuery(excludeMerged: false)->findOrFail($contact->getKey());

        if ((int) $this->getRecord()->getKey() !== (int) $freshContact->getKey()) {
            $this->redirect(ContactResource::getUrl('view', ['record' => $freshContact, 'tab' => $this->activeTab]));

            return;
        }

        $this->record = $freshContact;
    }

    protected function clearWorkspaceContactAfterDelete(): void
    {
        $this->redirect(ContactResource::getUrl('index'));
    }

    /**
     * @return array{
     *     backUrl:string,
     *     title:string,
     *     mergedRootLabel:?string,
     *     mergedRootUrl:?string,
     *     canEditProfile:bool
     * }
     */
    protected function buildHeaderViewData(Contact $record, array $profileViewData, array $dialogsViewData): array
    {
        $record->loadMissing('mergedInto');
        $mergedInto = $record->mergedInto;

        return [
            'backUrl' => ContactResource::getUrl('index'),
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
     * @return list<array{key:string,label:string,url:string,isActive:bool}>
     */
    protected function buildTabsViewData(): array
    {
        $tabs = [
            $this->makeTab(self::TAB_GENERAL, 'Общее'),
            $this->makeTab(self::TAB_QUESTIONNAIRES, 'Анкеты'),
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
    protected function buildProfileRows(Contact $record, bool $canEditProfile): array
    {
        $profileAction = $canEditProfile
            ? $this->makeAction(
                method: 'openEditProfileDialog',
                target: 'openEditProfileDialog,saveMountedContactProfile',
                label: 'Редактировать поле'
            )
            : null;

        return [
            $this->makeRow('Имя', 'first_name', $record->first_name ?? '—', $profileAction),
            $this->makeRow('Откуда знаем имя?', 'first_name_source', $this->resolveFirstNameSourceValue($record)),
            $this->makeRow('Как обработали имя?', 'first_name_resolution_method', $this->resolveFirstNameResolutionMethodValue($record)),
            $this->makeRow('Фамилия', 'last_name', $record->last_name ?? '—', $profileAction),
            $this->makeRow('Пол', 'gender', Contact::formatGender($record->gender), $profileAction),
            $this->makeRow('Возраст', 'effective_age_years', $record->effective_age_years !== null ? (string) $record->effective_age_years : '—'),
            $this->makeRow('Возраст из БД', 'age_years', $record->age_years !== null ? (string) $record->age_years : '—', $profileAction),
            $this->makeRow('Возрастной диапазон', 'age_range', Contact::formatAgeRange($record->age_range), $profileAction),
            $this->makeRow('Дата рождения', 'birth_date', $this->formatDate($record->birth_date), $profileAction),
        ];
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildLocationRows(Contact $record, bool $canEditProfile): array
    {
        $locationAction = $canEditProfile
            ? $this->makeAction(
                method: 'openEditProfileDialog',
                target: 'openEditProfileDialog,saveMountedContactProfile',
                label: 'Редактировать локацию'
            )
            : null;

        return [
            $this->makeRow('Страна', 'country', $record->country ?? '—', $locationAction),
            $this->makeRow('Город', 'city', $record->city ?? '—', $locationAction),
            $this->makeRow('Регион', 'region', $record->region ?? '—', $locationAction),
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
        $tagItems = $this->buildTagItems(
            $tagsViewData['tags'] ?? [],
            (bool) ($tagsViewData['canManageTags'] ?? false),
        );
        $phoneItems = $this->buildPhoneItems(
            $phoneNumbersViewData['phoneNumbers'] ?? [],
            (bool) ($phoneNumbersViewData['canEditPhones'] ?? false),
            (bool) ($phoneNumbersViewData['canDeletePhones'] ?? false),
        );
        $primaryPhone = collect($phoneNumbersViewData['phoneNumbers'] ?? [])
            ->firstWhere('is_primary', true) ?? ($phoneNumbersViewData['phoneNumbers'][0] ?? null);

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
            $this->makeRow('ID', 'id', (string) $record->id),
            $this->makeRow('Создан', 'created_at', $this->formatDateTime($record->created_at)),
            $this->makeRow('Обновлён', 'updated_at', $this->formatDateTime($record->updated_at)),
            $this->makeRow(
                'Теги контакта',
                '',
                $this->formatTagSummary($tagsViewData['tags'] ?? []),
                ($tagsViewData['canManageTags'] ?? false)
                    ? $this->makeAction(
                        method: 'openAddTagDialog',
                        target: 'openAddTagDialog,saveMountedContactTag',
                        label: 'Добавить тег'
                    )
                    : null,
                $tagItems,
            ),
            $this->makeRow(
                'Телефоны',
                '',
                $this->formatPhoneSummary($phoneNumbersViewData['phoneNumbers'] ?? []),
                is_array($primaryPhone) && ($phoneNumbersViewData['canEditPhones'] ?? false)
                    ? $this->makeAction(
                        method: sprintf('openEditPhoneDialog(%d)', (int) $primaryPhone['id']),
                        target: 'openEditPhoneDialog,saveMountedContactPhone',
                        label: 'Изменить основной номер'
                    )
                    : null,
                $phoneItems,
            ),
        ];
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildQuestionnaireRows(Contact $record, bool $canManageQuestionnaires): array
    {
        $questionnaireRun = $this->latestQuestionnaireRun($record);

        if ($questionnaireRun instanceof ContactQuestionnaireRun) {
            $fields = $this->questionnaireFieldsByKey($questionnaireRun);
            $answers = $questionnaireRun->answers;
            $requiredFieldsCount = count(array_filter(
                $fields,
                static fn (array $field): bool => (bool) ($field['required'] ?? false),
            ));
            $filledCount = $answers
                ->filter(fn (ContactQuestionnaireAnswer $answer): bool => in_array($answer->status, [
                    ContactQuestionnaireAnswer::STATUS_FILLED,
                    ContactQuestionnaireAnswer::STATUS_SKIPPED,
                ], true))
                ->count();
            $attemptsCount = $questionnaireRun->attempts->count();

            $rows = [
                $this->makeRow('Механика анкеты', 'questionnaire_engine', 'Новая анкета'),
                $this->makeRow('Шаблон', 'questionnaire_template', $this->formatQuestionnaireTemplate($questionnaireRun)),
                $this->makeRow('Статус анкеты', 'questionnaire_status', $this->formatQuestionnaireRunStatus($questionnaireRun->status)),
                $this->makeRow('Прогресс', 'questionnaire_progress', sprintf('%d / %d', $filledCount, max($requiredFieldsCount, $answers->count()))),
                $this->makeRow('Текущий вопрос', 'questionnaire_current_field', $this->formatQuestionnaireFieldLabel($questionnaireRun->current_field_key, $fields)),
                $this->makeRow('Начата', 'questionnaire_started_at', $this->formatDateTime($questionnaireRun->started_at)),
                $this->makeRow('Завершена', 'questionnaire_completed_at', $this->formatDateTime($questionnaireRun->completed_at)),
                $this->makeRow('Попыток', 'questionnaire_attempts_count', (string) $attemptsCount),
                $this->makeRow(
                    'Ответы',
                    'questionnaire_answers',
                    $answers->isNotEmpty() ? sprintf('%d полей', $answers->count()) : '—',
                    items: $this->buildQuestionnaireAnswerItems($questionnaireRun, $fields),
                ),
                $this->makeRow('Legacy-статус', 'data_collection_status', ContactResource::formatDataCollectionStatus($record->data_collection_status)),
            ];

            return array_merge($rows, $this->buildQuestionnaireOperatorRows($questionnaireRun, $canManageQuestionnaires));
        }

        return [
            $this->makeRow('Механика анкеты', 'questionnaire_engine', 'Старый collector'),
            $this->makeRow('Статус анкеты', 'data_collection_status', ContactResource::formatDataCollectionStatus($record->data_collection_status)),
            $this->makeRow('Текущий шаг', 'data_collection_current_field', ContactResource::formatDataCollectionField($record->data_collection_current_field)),
            $this->makeRow('Последний заданный шаг', 'data_collection_last_prompted_field', ContactResource::formatDataCollectionField($record->data_collection_last_prompted_field)),
            $this->makeRow('Анкета начата', 'data_collection_started_at', $this->formatDateTime($record->data_collection_started_at)),
            $this->makeRow('Текущий шаг начат', 'data_collection_current_field_started_at', $this->formatDateTime($record->data_collection_current_field_started_at)),
            $this->makeRow('Анкета завершена', 'data_collection_completed_at', $this->formatDateTime($record->data_collection_completed_at)),
            $this->makeRow('Попыток', 'data_collection_attempts_count', (string) ((int) $record->data_collection_attempts_count)),
        ];
    }

    /**
     * @return array{
     *     rows:list<array{
     *         id:int,
     *         template:string,
     *         status:string,
     *         progress:string,
     *         currentField:string,
     *         startedAt:string,
     *         completedAt:string,
     *         attemptsCount:string,
     *         answersCount:string,
     *         answerItems:list<array{
     *             kind:string,
     *             label:string,
     *             meta:?string,
     *             tone:?string,
     *             editAction:?string,
     *             editTarget:?string,
     *             deleteAction:?string,
     *             deleteTarget:?string
     *         }>,
     *         cancelAction:?array{method:string,target:string,label:string,icon:string},
     *         resetAction:?array{method:string,target:string,label:string,icon:string}
     *     }>,
     *     legacyRows:list<array{label:string,key:string,value:string}>,
     * }
     */
    protected function buildQuestionnaireRunsViewData(Contact $record, bool $canManageQuestionnaires): array
    {
        $runs = ContactQuestionnaireRun::query()
            ->with([
                'template',
                'templateVersion',
                'answers.attempts',
                'attempts',
            ])
            ->where('contact_id', $record->getKey())
            ->latest('id')
            ->get();

        $rows = $runs
            ->map(function (ContactQuestionnaireRun $run) use ($canManageQuestionnaires): array {
                $fields = $this->questionnaireFieldsByKey($run);
                $answers = $run->answers;
                $requiredFieldsCount = count(array_filter(
                    $fields,
                    static fn (array $field): bool => (bool) ($field['required'] ?? false),
                ));
                $filledCount = $answers
                    ->filter(fn (ContactQuestionnaireAnswer $answer): bool => in_array($answer->status, [
                        ContactQuestionnaireAnswer::STATUS_FILLED,
                        ContactQuestionnaireAnswer::STATUS_SKIPPED,
                    ], true))
                    ->count();
                $attemptsCount = $run->attempts->count();
                $canReset = $canManageQuestionnaires && $run->status !== ContactQuestionnaireRun::STATUS_RESET;
                $canCancel = $canManageQuestionnaires && in_array($run->status, ContactQuestionnaireRun::activeStatuses(), true);

                return [
                    'id' => (int) $run->id,
                    'template' => $this->formatQuestionnaireTemplate($run),
                    'status' => $this->formatQuestionnaireRunStatus($run->status),
                    'progress' => sprintf('%d / %d', $filledCount, max($requiredFieldsCount, $answers->count())),
                    'currentField' => $this->formatQuestionnaireFieldLabel($run->current_field_key, $fields),
                    'startedAt' => $this->formatDateTime($run->started_at),
                    'completedAt' => $this->formatDateTime($run->completed_at),
                    'attemptsCount' => (string) $attemptsCount,
                    'answersCount' => $answers->isNotEmpty() ? sprintf('%d полей', $answers->count()) : '—',
                    'answerItems' => $this->buildQuestionnaireAnswerItems($run, $fields),
                    'cancelAction' => $canCancel
                        ? $this->makeAction(
                            method: sprintf('cancelMountedContactQuestionnaireRun(%d)', (int) $run->id),
                            target: 'cancelMountedContactQuestionnaireRun',
                            label: 'Отменить анкету',
                            icon: 'heroicon-m-x-circle',
                        )
                        : null,
                    'resetAction' => $canReset
                        ? $this->makeAction(
                            method: sprintf('resetMountedContactQuestionnaireRun(%d)', (int) $run->id),
                            target: 'resetMountedContactQuestionnaireRun',
                            label: 'Сбросить анкету',
                            icon: 'heroicon-m-arrow-path',
                        )
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'rows' => $rows,
            'legacyRows' => $rows === [] ? $this->buildQuestionnaireRows($record, false) : [],
        ];
    }

    public function cancelMountedContactQuestionnaireRun(int|string $runId): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось отменить анкету')) {
            return;
        }

        $record = $this->resolveWorkspaceContactOrNotify('Не удалось отменить анкету');

        if (! $record instanceof Contact) {
            return;
        }

        try {
            $run = $this->resolveMountedQuestionnaireRun($record, $runId);

            if (! in_array($run->status, ContactQuestionnaireRun::activeStatuses(), true)) {
                Notification::make()
                    ->warning()
                    ->title('Анкета уже не активна')
                    ->body('Отменять можно только активное прохождение анкеты.')
                    ->send();

                return;
            }

            $run->forceFill([
                'status' => ContactQuestionnaireRun::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'last_dialog_id' => $run->last_dialog_id,
            ])->save();

            $this->replaceWorkspaceContactWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Анкета отменена')
                ->body('Ответы сохранены, прохождение остановлено.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось отменить анкету')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function resetMountedContactQuestionnaireRun(int|string $runId): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось сбросить анкету')) {
            return;
        }

        $record = $this->resolveWorkspaceContactOrNotify('Не удалось сбросить анкету');

        if (! $record instanceof Contact) {
            return;
        }

        try {
            $run = $this->resolveMountedQuestionnaireRun($record, $runId);
            $employee = $this->resolveCurrentEmployee();

            if ($run->status === ContactQuestionnaireRun::STATUS_RESET) {
                Notification::make()
                    ->warning()
                    ->title('Анкета уже сброшена')
                    ->body('Это прохождение уже помечено как сброшенное.')
                    ->send();

                return;
            }

            $run->forceFill([
                'status' => ContactQuestionnaireRun::STATUS_RESET,
                'reset_at' => now(),
                'reset_by' => $employee->id,
            ])->save();

            $this->replaceWorkspaceContactWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Анкета сброшена')
                ->body('Старые ответы сохранены в истории, новый запуск сможет создать новое прохождение.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось сбросить анкету')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    /**
     * @return list<array{label:string,key:string,value:string}>
     */
    protected function buildQuestionnaireOperatorRows(ContactQuestionnaireRun $run, bool $canManageQuestionnaires): array
    {
        if (! $canManageQuestionnaires || $run->status === ContactQuestionnaireRun::STATUS_RESET) {
            return [];
        }

        $rows = [];

        if (in_array($run->status, ContactQuestionnaireRun::activeStatuses(), true)) {
            $rows[] = $this->makeRow(
                'Отменить прохождение',
                'questionnaire_cancel',
                'Остановить анкету, ответы оставить',
                $this->makeAction(
                    method: sprintf('cancelMountedContactQuestionnaireRun(%d)', (int) $run->id),
                    target: 'cancelMountedContactQuestionnaireRun',
                    label: 'Отменить анкету',
                    icon: 'heroicon-m-x-circle',
                ),
            );
        }

        $rows[] = $this->makeRow(
            'Сбросить прохождение',
            'questionnaire_reset',
            'Разрешить пройти анкету заново',
            $this->makeAction(
                method: sprintf('resetMountedContactQuestionnaireRun(%d)', (int) $run->id),
                target: 'resetMountedContactQuestionnaireRun',
                label: 'Сбросить анкету',
                icon: 'heroicon-m-arrow-path',
            ),
        );

        return $rows;
    }

    protected function resolveMountedQuestionnaireRun(Contact $record, int|string $runId): ContactQuestionnaireRun
    {
        $run = ContactQuestionnaireRun::query()
            ->whereKey((int) $runId)
            ->where('contact_id', $record->getKey())
            ->first();

        if (! $run instanceof ContactQuestionnaireRun) {
            throw new RuntimeException('Прохождение анкеты не найдено.');
        }

        return $run;
    }

    protected function latestQuestionnaireRun(Contact $record): ?ContactQuestionnaireRun
    {
        return ContactQuestionnaireRun::query()
            ->with([
                'template',
                'templateVersion',
                'answers.attempts',
                'attempts',
            ])
            ->where('contact_id', $record->getKey())
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function questionnaireFieldsByKey(ContactQuestionnaireRun $run): array
    {
        $fields = $run->templateVersion?->fields_payload;

        if (! is_array($fields)) {
            return [];
        }

        $indexed = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fieldKey = $field['field_key'] ?? null;

            if (is_string($fieldKey) && $fieldKey !== '') {
                $indexed[$fieldKey] = $field;
            }
        }

        return $indexed;
    }

    protected function formatQuestionnaireTemplate(ContactQuestionnaireRun $run): string
    {
        $name = $run->template?->name ?: 'Анкета';
        $version = $run->templateVersion?->version;

        return $version !== null ? sprintf('%s v%d', $name, (int) $version) : $name;
    }

    protected function formatQuestionnaireRunStatus(?string $status): string
    {
        return ContactQuestionnaireRun::statusOptions()[$status] ?? '—';
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
     */
    protected function formatQuestionnaireFieldLabel(?string $fieldKey, array $fields): string
    {
        if ($fieldKey === null || $fieldKey === '') {
            return '—';
        }

        $label = $fields[$fieldKey]['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : $fieldKey;
    }

    /**
     * @param  array<string, array<string, mixed>>  $fields
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
    protected function buildQuestionnaireAnswerItems(ContactQuestionnaireRun $run, array $fields): array
    {
        $attemptsByField = $run->attempts->groupBy('field_key');

        return $run->answers
            ->map(function (ContactQuestionnaireAnswer $answer) use ($attemptsByField, $fields): array {
                $label = $this->formatQuestionnaireFieldLabel($answer->field_key, $fields);
                $value = $answer->display_value ?: $answer->value ?: '—';
                $status = ContactQuestionnaireAnswer::statusOptions()[$answer->status] ?? $answer->status;
                $attempts = $answer->attempts_count > 0
                    ? sprintf('попыток: %d', (int) $answer->attempts_count)
                    : null;
                $lastAttempt = $attemptsByField->get($answer->field_key, collect())->last();
                $rawAnswer = $lastAttempt instanceof ContactQuestionnaireAttempt && filled($lastAttempt->raw_answer)
                    ? sprintf('ответ: %s', (string) str($lastAttempt->raw_answer)->limit(80))
                    : null;

                return [
                    'kind' => 'questionnaire-answer',
                    'label' => sprintf('%s: %s', $label, $value),
                    'meta' => implode(' · ', array_filter([$status, $attempts, $rawAnswer])),
                    'tone' => null,
                    'editAction' => null,
                    'editTarget' => null,
                    'deleteAction' => null,
                    'deleteTarget' => null,
                ];
            })
            ->values()
            ->all();
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
    ): array {
        return [
            'label' => $label,
            'key' => $key,
            'value' => $value !== '' ? $value : '—',
            'action' => $action,
            'items' => $items,
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
            self::TAB_QUESTIONNAIRES,
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
