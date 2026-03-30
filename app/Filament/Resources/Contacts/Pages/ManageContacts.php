<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\Bots\SendManualContactReplyAction;
use App\Services\Contacts\ClaimContactAction;
use App\Services\Contacts\DeleteContactAction;
use App\Services\Contacts\DeleteContactPhoneAction;
use App\Services\Contacts\ReleaseContactAssignmentAction;
use App\Services\Contacts\SetContactAutoReplyEnabledAction;
use App\Services\Contacts\SetContactAssigneeAction;
use App\Services\Contacts\UpdateContactProfileAction;
use App\Services\Contacts\UpdateContactPhoneAction;
use App\Services\DataCollection\ResumeContactDataCollectionAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use RuntimeException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    public string $inlineReplyText = '';
    public bool $showAssignContactDialog = false;
    public string $selectedAssigneeId = '';
    public bool $showEditPhoneDialog = false;
    public string $editingPhoneId = '';
    public string $editingPhoneRaw = '';
    public bool $showEditProfileDialog = false;
    public string $editingFirstName = '';
    public string $editingLastName = '';
    public string $editingAgeYears = '';
    public string $editingAgeRange = '';
    public string $editingBirthDate = '';
    public string $editingCountry = '';
    public string $editingCity = '';
    public bool $showDeletePhoneDialog = false;
    public string $deletingPhoneId = '';
    public string $deletingPhoneLabel = '';
    public bool $showDeleteContactDialog = false;
    public string $deletingContactLabel = '';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    public function claimMountedContact(): void
    {
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

            app(ClaimContactAction::class)->handle($record, $employee);

            $this->replaceMountedTableAction('view', (string) $record->id);

            Notification::make()
                ->success()
                ->title('Контакт взят в работу')
                ->body('Теперь ручной ответ доступен только вам.')
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

            app(ReleaseContactAssignmentAction::class)->handle($record, $employee);

            $this->replaceMountedTableAction('view', (string) $record->id);

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

    public function sendInlineReply(): void
    {
        $validated = $this->validate([
            'inlineReplyText' => ['required', 'string', 'max:2000'],
        ]);

        $text = trim((string) ($validated['inlineReplyText'] ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([
                'inlineReplyText' => 'Текст ответа обязателен.',
            ]);
        }

        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось отправить ответ')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        try {
            $employee = $this->resolveCurrentEmployee();

            app(SendManualContactReplyAction::class)->handle(
                $record,
                $employee,
                $text,
            );

            $this->inlineReplyText = '';

            Notification::make()
                ->success()
                ->title('Ответ отправлен')
                ->body('Сообщение отправлено и сохранено в истории контакта.')
                ->send();
        } catch (Throwable $throwable) {
            Notification::make()
                ->danger()
                ->title('Не удалось отправить ответ')
                ->body($throwable->getMessage())
                ->send();
        }
    }

    public function openAssignContactDialog(): void
    {
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

            app(SetContactAssigneeAction::class)->handle($record, $employee, $assigneeId);

            $this->showAssignContactDialog = false;
            $this->selectedAssigneeId = '';
            $this->replaceMountedTableAction('view', (string) $record->id);

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
        $this->editingAgeYears = $record->age_years !== null ? (string) $record->age_years : '';
        $this->editingAgeRange = (string) ($record->age_range ?? '');
        $this->editingBirthDate = $record->birth_date?->toDateString() ?? '';
        $this->editingCountry = (string) ($record->country ?? '');
        $this->editingCity = (string) ($record->city ?? '');
        $this->showEditProfileDialog = true;
    }

    public function closeEditProfileDialog(): void
    {
        $this->resetProfileEditingState();
    }

    public function saveMountedContactProfile(): void
    {
        $validated = $this->validate([
            'editingFirstName' => ['nullable', 'string', 'max:255'],
            'editingLastName' => ['nullable', 'string', 'max:255'],
            'editingAgeYears' => ['nullable', 'integer', 'min:0', 'max:150'],
            'editingAgeRange' => ['nullable', 'string', Rule::in(array_keys(Contact::ageRangeOptions()))],
            'editingBirthDate' => ['nullable', 'date', 'before_or_equal:today'],
            'editingCountry' => ['nullable', 'string', 'max:255'],
            'editingCity' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            app(UpdateContactProfileAction::class)->handle($record, [
                'first_name' => $validated['editingFirstName'] ?? null,
                'last_name' => $validated['editingLastName'] ?? null,
                'age_years' => $validated['editingAgeYears'] ?? null,
                'age_range' => $validated['editingAgeRange'] ?? null,
                'birth_date' => $validated['editingBirthDate'] ?? null,
                'country' => $validated['editingCountry'] ?? null,
                'city' => $validated['editingCity'] ?? null,
            ]);

            $this->resetProfileEditingState();
            $this->replaceMountedTableAction('view', (string) $record->id);

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
            $this->replaceMountedTableAction('view', (string) $record->id);

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
        $record = $this->getMountedTableActionRecord();

        if (! $record instanceof Contact) {
            Notification::make()
                ->danger()
                ->title('Не удалось открыть удаление контакта')
                ->body('Не удалось определить текущий контакт.')
                ->send();

            return;
        }

        $this->deletingContactLabel = $record->display_name;
        $this->showDeleteContactDialog = true;
    }

    public function closeDeleteContactDialog(): void
    {
        $this->resetContactDeletingState();
    }

    public function deleteMountedContact(): void
    {
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
        try {
            $record = $this->getMountedTableActionRecord();

            if (! $record instanceof Contact) {
                throw new RuntimeException('Не удалось определить текущий контакт.');
            }

            $phoneNumber = $this->resolveMountedContactPhoneNumber($this->deletingPhoneId);

            app(DeleteContactPhoneAction::class)->handle($phoneNumber);

            $this->resetPhoneDeletingState();
            $this->replaceMountedTableAction('view', (string) $record->id);

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

            $this->replaceMountedTableAction('view', (string) $record->id);

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

    public function canClaimContact(?Contact $contact = null): bool
    {
        return $this->getContactOwnershipState($contact) === 'unassigned';
    }

    public function canReleaseContact(?Contact $contact = null): bool
    {
        return $this->getContactOwnershipState($contact) === 'mine';
    }

    public function canSendInlineReply(?Contact $contact = null): bool
    {
        return in_array($this->getContactOwnershipState($contact), ['mine', 'unassigned'], true);
    }

    public function getInlineReplyBlockedReason(?Contact $contact = null): ?string
    {
        return match ($this->getContactOwnershipState($contact)) {
            'other' => 'Контакт уже назначен другому сотруднику. Ручной ответ недоступен.',
            default => null,
        };
    }

    public function getContactOwnershipState(?Contact $contact = null): string
    {
        if (! $contact instanceof Contact) {
            return 'unknown';
        }

        $contact->loadMissing('assignedUser');

        if (! $contact->isAssigned()) {
            return 'unassigned';
        }

        /** @var User|null $employee */
        $employee = auth()->user();

        if ($employee instanceof User && $contact->isAssignedTo($employee)) {
            return 'mine';
        }

        return 'other';
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
            app(SetContactAutoReplyEnabledAction::class)->handle($record, $isEnabled);

            $this->replaceMountedTableAction('view', (string) $record->id);

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
        $this->editingAgeYears = '';
        $this->editingAgeRange = '';
        $this->editingBirthDate = '';
        $this->editingCountry = '';
        $this->editingCity = '';
        $this->resetErrorBag([
            'editingFirstName',
            'editingLastName',
            'editingAgeYears',
            'editingAgeRange',
            'editingBirthDate',
            'editingCountry',
            'editingCity',
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
    }
}
