<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ClaimContactAction;
use App\Services\Contacts\DeleteContactAction;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\ReleaseContactAssignmentAction;
use App\Services\Contacts\ResolveContactDeletePreviewAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Contacts\SetContactAutoReplyEnabledAction;
use App\Services\Contacts\SetContactAssigneeAction;
use App\Services\Contacts\UpdateContactProfileAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use App\Services\DataCollection\ResumeContactDataCollectionAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    public bool $showAssignContactDialog = false;
    public string $selectedAssigneeId = '';
    public bool $showEditPhoneDialog = false;
    public string $editingPhoneId = '';
    public string $editingPhoneRaw = '';
    public bool $showEditProfileDialog = false;
    public string $editingFirstName = '';
    public string $editingLastName = '';
    public string $editingGender = '';
    public string $editingAgeYears = '';
    public string $editingAgeRange = '';
    public string $editingBirthDate = '';
    public string $editingCountry = '';
    public string $editingCity = '';
    public string $editingRegion = '';
    public bool $showDeletePhoneDialog = false;
    public string $deletingPhoneId = '';
    public string $deletingPhoneLabel = '';
    public bool $showDeleteContactDialog = false;
    public string $deletingContactLabel = '';
    public bool $deletingContactHasMergeHistory = false;

    /**
     * @var array<int, array{label:string,value:int}>
     */
    public array $deletingContactCounts = [];

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function resolveTableRecord(?string $key): Model | array | null
    {
        if ($key === null) {
            return null;
        }

        return ContactResource::getTableRecordQuery(excludeMerged: false)->find($key);
    }

    public function claimMountedContact(): void
    {
        if ($this->abortIfContactOwnershipForbidden('Не удалось взять контакт в работу')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось взять контакт в работу')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            $contact = app(ClaimContactAction::class)->handle($record, $employee);

            $this->remountViewForContact($contact);

            Notification::make()
                ->success()
                ->title('Контакт взят в работу')
                ->body('Теперь вы указаны ответственным за этот контакт.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось взять контакт в работу')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function releaseMountedContact(): void
    {
        if ($this->abortIfContactOwnershipForbidden('Не удалось снять контакт с работы')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось снять контакт с работы')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            $contact = app(ReleaseContactAssignmentAction::class)->handle($record, $employee);

            $this->remountViewForContact($contact);

            Notification::make()
                ->success()
                ->title('Контакт освобождён')
                ->body('Контакт снова доступен для взятия в работу.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось снять контакт с работы')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function openAssignContactDialog(): void
    {
        if ($this->abortIfContactOwnershipForbidden('Не удалось открыть выбор ответственного')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть выбор ответственного')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        $this->selectedAssigneeId = filled($record->assigned_user_id)
            ? (string) $record->assigned_user_id
            : '';
        $this->showAssignContactDialog = true;
    }

    public function closeAssignContactDialog(): void
    {
        $this->showAssignContactDialog = false;
        $this->selectedAssigneeId = '';
    }

    public function saveMountedContactAssignee(): void
    {
        if ($this->abortIfContactOwnershipForbidden('Не удалось сохранить ответственного')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось сохранить ответственного')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();
            $assigneeId = $this->selectedAssigneeId !== ''
                ? (int) $this->selectedAssigneeId
                : null;

            $contact = app(SetContactAssigneeAction::class)->handle($record, $employee, $assigneeId);

            $this->showAssignContactDialog = false;
            $this->selectedAssigneeId = '';
            $this->remountViewForContact($contact);

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

    public function openEditPhoneDialog(int|string $phoneId): void
    {
        if ($this->abortIfContactPhoneEditForbidden('Не удалось открыть редактирование номера')) {
            return;
        }

        try {
            $phoneNumber = $this->resolveMountedContactPhoneNumber($phoneId);

            $this->editingPhoneId = (string) $phoneNumber->id;
            $this->editingPhoneRaw = $phoneNumber->phone_raw;
            $this->showEditPhoneDialog = true;
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть редактирование номера')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function openEditProfileDialog(): void
    {
        if ($this->abortIfContactProfileForbidden('Не удалось открыть редактирование профиля')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть редактирование профиля')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        $this->editingFirstName = (string) ($record->first_name ?? '');
        $this->editingLastName = (string) ($record->last_name ?? '');
        $this->editingGender = (string) ($record->gender ?? '');
        $this->editingAgeYears = $record->age_years !== null ? (string) $record->age_years : '';
        $this->editingAgeRange = (string) ($record->age_range ?? '');
        $this->editingBirthDate = $record->birth_date?->toDateString() ?? '';
        $this->editingCountry = (string) ($record->country ?? '');
        $this->editingCity = (string) ($record->city ?? '');
        $this->editingRegion = (string) ($record->region ?? '');
        $this->showEditProfileDialog = true;
    }

    public function closeEditProfileDialog(): void
    {
        $this->resetProfileEditingState();
    }

    public function saveMountedContactProfile(): void
    {
        if ($this->abortIfContactProfileForbidden('Не удалось обновить профиль')) {
            return;
        }

        $validated = $this->validate([
            'editingFirstName' => ['nullable', 'string', 'max:255'],
            'editingLastName' => ['nullable', 'string', 'max:255'],
            'editingGender' => ['nullable', 'string', Rule::in(array_keys(Contact::genderOptions()))],
            'editingAgeYears' => ['nullable', 'integer', 'min:0', 'max:150'],
            'editingAgeRange' => ['nullable', 'string', Rule::in(array_keys(Contact::ageRangeOptions()))],
            'editingBirthDate' => ['nullable', 'date', 'before_or_equal:today'],
            'editingCountry' => ['nullable', 'string', 'max:255'],
            'editingCity' => ['nullable', 'string', 'max:255'],
            'editingRegion' => ['nullable', 'string', Rule::in(array_keys(Contact::russianRegionOptions()))],
        ]);

        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            $contact = app(UpdateContactProfileAction::class)->handle($record, [
                'first_name' => $validated['editingFirstName'] ?? null,
                'last_name' => $validated['editingLastName'] ?? null,
                'gender' => $validated['editingGender'] ?? null,
                'age_years' => $validated['editingAgeYears'] ?? null,
                'age_range' => $validated['editingAgeRange'] ?? null,
                'birth_date' => $validated['editingBirthDate'] ?? null,
                'country' => $validated['editingCountry'] ?? null,
                'city' => $validated['editingCity'] ?? null,
                'region' => $validated['editingRegion'] ?? null,
            ]);

            $this->resetProfileEditingState();
            $this->remountViewForContact($contact);

            Notification::make()
                ->success()
                ->title('Профиль обновлён')
                ->body('Изменения сохранены.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось обновить профиль')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function closeEditPhoneDialog(): void
    {
        $this->resetPhoneEditingState();
    }

    public function saveMountedContactPhone(): void
    {
        if ($this->abortIfContactPhoneEditForbidden('Не удалось обновить номер')) {
            return;
        }

        $validated = $this->validate([
            'editingPhoneRaw' => ['required', 'string', 'max:64'],
        ]);

        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            $phoneNumber = $this->resolveMountedContactPhoneNumber($this->editingPhoneId);

            app(UpdateContactPhoneAction::class)->handle(
                $phoneNumber,
                (string) $validated['editingPhoneRaw'],
            );

            $this->resetPhoneEditingState();
            $this->replaceMountedTableActionWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Номер обновлён')
                ->body('Изменения сохранены.')
                ->send();
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages([
                'editingPhoneRaw' => $exception->getMessage(),
            ]);
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось обновить номер')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function openDeletePhoneDialog(int|string $phoneId): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось открыть удаление номера')) {
            return;
        }

        try {
            $phoneNumber = $this->resolveMountedContactPhoneNumber($phoneId);

            $this->deletingPhoneId = (string) $phoneNumber->id;
            $this->deletingPhoneLabel = $phoneNumber->phone_raw;
            $this->showDeletePhoneDialog = true;
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть удаление номера')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function closeDeletePhoneDialog(): void
    {
        $this->resetPhoneDeletingState();
    }

    public function openDeleteContactDialog(): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось открыть удаление контакта')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть удаление контакта')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        $preview = app(ResolveContactDeletePreviewAction::class)->handle($record);

        $this->deletingContactLabel = $preview->rootContact->display_name;
        $this->deletingContactHasMergeHistory = $preview->hasMergeHistory;
        $this->deletingContactCounts = [
            ['label' => 'Контактов', 'value' => $preview->contactsCount],
            ['label' => 'Диалогов', 'value' => $preview->dialogsCount],
            ['label' => 'Сообщений', 'value' => $preview->messagesCount],
            ['label' => 'Телефонов', 'value' => $preview->phonesCount],
            ['label' => 'Идентификаторов', 'value' => $preview->identitiesCount],
        ];
        $this->showDeleteContactDialog = true;
    }

    public function closeDeleteContactDialog(): void
    {
        $this->resetContactDeletingState();
    }

    public function deleteMountedContact(): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось удалить контакт')) {
            return;
        }

        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            app(DeleteContactAction::class)->handle($record);

            $this->resetContactDeletingState();
            $this->unmountTableAction();

            Notification::make()
                ->success()
                ->title('Контакт удалён')
                ->body('Клиент и связанная история удалены.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось удалить контакт')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function deleteMountedContactPhone(): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось удалить номер')) {
            return;
        }

        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            $phoneNumber = $this->resolveMountedContactPhoneNumber($this->deletingPhoneId);

            app(DeleteContactPhoneAction::class)->handle($phoneNumber);

            $this->resetPhoneDeletingState();
            $this->replaceMountedTableActionWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Номер удалён')
                ->body('Номер телефона удалён из контакта.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось удалить номер')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function enableMountedContactAutoReply(): void
    {
        $this->setMountedContactAutoReplyEnabled(true);
    }

    public function disableMountedContactAutoReply(): void
    {
        $this->setMountedContactAutoReplyEnabled(false);
    }

    public function resumeMountedContactDataCollection(): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось возобновить анкету')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось возобновить анкету')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $nextField = app(ResumeContactDataCollectionAction::class)->handle($record);

            if ($nextField === null) {
                Notification::make()
                    ->warning()
                    ->title('Анкета уже заполнена')
                    ->body('Для этого контакта нет незаполненных шагов анкеты.')
                    ->send();

                return;
            }

            $this->replaceMountedTableActionWithEffectiveContact($record);

            Notification::make()
                ->success()
                ->title('Анкета возобновлена')
                ->body(sprintf('Анкета возобновлена с шага: %s.', $this->formatDataCollectionField($nextField)))
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось возобновить анкету')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    protected function resolveCurrentEmployee(): User
    {
        /** @var User|null $employee */
        $employee = auth()->user();

        if (! $employee instanceof User) {
            throw new \RuntimeException('Не удалось определить текущего сотрудника.');
        }

        return $employee;
    }

    protected function setMountedContactAutoReplyEnabled(bool $isEnabled): void
    {
        if ($this->abortIfContactMutationForbidden('Не удалось обновить автоответы')) {
            return;
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось обновить автоответы')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $contact = app(SetContactAutoReplyEnabledAction::class)->handle($record, $isEnabled);

            $this->remountViewForContact($contact);

            Notification::make()
                ->success()
                ->title($isEnabled ? 'Автоответы включены' : 'Автоответы отключены')
                ->body($isEnabled
                    ? 'Автоответы снова будут отправляться автоматически.'
                    : 'Для этого контакта автоответы больше не отправляются автоматически.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось обновить автоответы')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    protected function formatDataCollectionField(?string $field): string
    {
        return match ($field) {
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME => 'Имя',
            Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY => 'Город проживания',
            Contact::DATA_COLLECTION_FIELD_COUNTRY => 'Страна',
            Contact::DATA_COLLECTION_FIELD_CITY => 'Город',
            Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM => 'Регион',
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE => 'Возраст',
            default => '—',
        };
    }

    protected function resolveMountedContactPhoneNumber(int|string $phoneId): \App\Models\ContactPhoneNumber
    {
        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            throw new RuntimeException('Не удалось определить текущий контакт.');
        }

        $phoneNumber = $record->phoneNumbers()
            ->whereKey((int) $phoneId)
            ->first();

        if (! $phoneNumber instanceof \App\Models\ContactPhoneNumber) {
            throw new RuntimeException('Не удалось определить выбранный номер телефона.');
        }

        return $phoneNumber;
    }

    protected function replaceMountedTableActionWithEffectiveContact(Contact $contact): void
    {
        $effectiveContact = app(ResolveRootContactAction::class)->handle($contact);

        $this->remountViewForContact($effectiveContact);
    }

    protected function remountViewForContact(Contact $contact): void
    {
        $effectiveContact = app(ResolveRootContactAction::class)->handle($contact);

        $this->unmountTableAction(false);
        $this->mountTableAction('view', (string) $effectiveContact->id);
    }

    protected function resetPhoneEditingState(): void
    {
        $this->showEditPhoneDialog = false;
        $this->editingPhoneId = '';
        $this->editingPhoneRaw = '';
        $this->resetErrorBag('editingPhoneRaw');
    }

    protected function resetProfileEditingState(): void
    {
        $this->showEditProfileDialog = false;
        $this->editingFirstName = '';
        $this->editingLastName = '';
        $this->editingGender = '';
        $this->editingAgeYears = '';
        $this->editingAgeRange = '';
        $this->editingBirthDate = '';
        $this->editingCountry = '';
        $this->editingCity = '';
        $this->editingRegion = '';
        $this->resetErrorBag([
            'editingFirstName',
            'editingLastName',
            'editingGender',
            'editingAgeYears',
            'editingAgeRange',
            'editingBirthDate',
            'editingCountry',
            'editingCity',
            'editingRegion',
        ]);
    }

    protected function resetPhoneDeletingState(): void
    {
        $this->showDeletePhoneDialog = false;
        $this->deletingPhoneId = '';
        $this->deletingPhoneLabel = '';
    }

    protected function resetContactDeletingState(): void
    {
        $this->showDeleteContactDialog = false;
        $this->deletingContactLabel = '';
        $this->deletingContactHasMergeHistory = false;
        $this->deletingContactCounts = [];
    }

    protected function abortIfContactMutationForbidden(string $title): bool
    {
        if ($this->canCurrentEmployeeManageContactMutations()) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body('Это действие доступно только администратору.')
            ->send();

        return true;
    }

    protected function abortIfContactProfileForbidden(string $title): bool
    {
        if ($this->canCurrentEmployeeManageContactProfile()) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body('Это действие недоступно текущему сотруднику.')
            ->send();

        return true;
    }

    protected function abortIfContactOwnershipForbidden(string $title): bool
    {
        if ($this->canCurrentEmployeeManageContactOwnership()) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body('Это действие недоступно текущему сотруднику.')
            ->send();

        return true;
    }

    protected function abortIfContactPhoneEditForbidden(string $title): bool
    {
        if ($this->canCurrentEmployeeEditExistingContactPhones()) {
            return false;
        }

        Notification::make()
            ->danger()
            ->title($title)
            ->body('Это действие недоступно текущему сотруднику.')
            ->send();

        return true;
    }

    protected function canCurrentEmployeeManageContactMutations(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canManageContactWorkspaceMutations();
    }

    protected function canCurrentEmployeeManageContactProfile(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canManageContactProfile();
    }

    protected function canCurrentEmployeeManageContactOwnership(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canManageContactOwnership();
    }

    protected function canCurrentEmployeeEditExistingContactPhones(): bool
    {
        $employee = auth()->user();

        return $employee instanceof User
            && $employee->canEditExistingContactPhones();
    }
}
