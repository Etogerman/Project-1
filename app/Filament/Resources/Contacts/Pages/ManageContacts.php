<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\Bots\SendManualContactReplyAction;
use App\Services\Contacts\ClaimContactAction;
use App\Services\Contacts\ReleaseContactAssignmentAction;
use App\Services\Contacts\SetContactAutoReplyEnabledAction;
use App\Services\Contacts\SetContactAssigneeAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    public string $inlineReplyText = '';
    public bool $showAssignContactDialog = false;
    public string $selectedAssigneeId = '';

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

    public function enableMountedContactAutoReply(): void
    {
        $this->setMountedContactAutoReplyEnabled(true);
    }

    public function disableMountedContactAutoReply(): void
    {
        $this->setMountedContactAutoReplyEnabled(false);
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
}
