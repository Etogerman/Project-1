<?php

namespace App\Filament\Resources\Contacts\Pages;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Contact;
use App\Models\User;
use App\Services\Bots\SendManualContactReplyAction;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Validation\ValidationException;
use Throwable;

class ManageContacts extends ManageRecords
{
    protected static string $resource = ContactResource::class;

    public string $inlineReplyText = '';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
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
            /** @var User|null $employee */
            $employee = auth()->user();

            if (! $employee instanceof User) {
                throw new \RuntimeException('Не удалось определить текущего сотрудника.');
            }

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
}
