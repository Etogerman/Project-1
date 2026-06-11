<?php

namespace App\Services\DataCollection;

use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\ResolveDialogRouteSourceAction;
use RuntimeException;

class ResumeContactDataCollectionAction
{
    public function __construct(
        protected ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        protected ResolveRootContactAction $resolveRootContactAction,
        protected ResolveDialogRouteSourceAction $resolveDialogRouteSourceAction,
    ) {}

    public function handle(Contact $contact): ?string
    {
        $contact = $this->resolveRootContactAction->handle($contact);

        if ($contact->data_collection_status === Contact::DATA_COLLECTION_STATUS_ACTIVE) {
            throw new RuntimeException('Сбор данных уже находится в процессе.');
        }

        if (! $contact->phoneNumbers()->exists()) {
            throw new RuntimeException('Для возобновления сбора данных у контакта должен быть телефон.');
        }

        $nextField = $this->resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            return null;
        }

        $sourceMessage = $this->resolveResumeSourceMessage($contact);

        if (! $sourceMessage instanceof Message) {
            throw new RuntimeException('Не удалось определить сообщение для возобновления сбора данных.');
        }

        $contact->startDataCollection($nextField);

        ProcessDataCollectionQuestionJob::dispatch($sourceMessage->id, true, $contact->id, $nextField);

        return $nextField;
    }

    protected function resolveSourceMessage(Dialog $dialog): ?Message
    {
        $inboundMessage = Message::query()
            ->where('dialog_id', $dialog->id)
            ->where('direction', Message::DIRECTION_INBOUND)
            ->orderByDesc('id')
            ->first();

        if ($inboundMessage instanceof Message) {
            return $inboundMessage;
        }

        return Message::query()
            ->where('dialog_id', $dialog->id)
            ->orderByDesc('id')
            ->first();
    }

    protected function resolveResumeSourceMessage(Contact $contact): ?Message
    {
        $routeDialog = $this->resolveDialogRouteSourceAction->forContact($contact);

        if ($routeDialog instanceof Dialog) {
            $directSourceMessage = $this->resolveSourceMessage($routeDialog);

            if ($directSourceMessage instanceof Message) {
                return $directSourceMessage;
            }
        }

        return $contact->messages()
            ->whereNotNull('channel_id')
            ->whereNotNull('contact_identity_id')
            ->orderByDesc('id')
            ->get()
            ->first(function (Message $message): bool {
                return $this->resolveDialogRouteSourceAction->forMessage($message) instanceof Dialog
                    || $this->resolveDialogRouteSourceAction->fallbackFromLegacyMessage($message) instanceof Dialog;
            });
    }
}
