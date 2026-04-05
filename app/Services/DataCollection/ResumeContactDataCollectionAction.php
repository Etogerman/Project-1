<?php

namespace App\Services\DataCollection;

use App\Jobs\ProcessDataCollectionQuestionJob;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use RuntimeException;

class ResumeContactDataCollectionAction
{
    public function __construct(
        protected ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        protected ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact): ?string
    {
        $contact = $this->resolveRootContactAction->handle($contact);

        if ($contact->data_collection_status === Contact::DATA_COLLECTION_STATUS_ACTIVE) {
            throw new RuntimeException('Анкета уже находится в процессе.');
        }

        if (! $contact->phoneNumbers()->exists()) {
            throw new RuntimeException('Для возобновления анкеты у контакта должен быть телефон.');
        }

        $nextField = $this->resolveNextDataCollectionFieldAction->handle($contact);

        if ($nextField === null) {
            return null;
        }

        $sourceMessage = $this->resolveSourceMessage($contact);

        if (! $sourceMessage instanceof Message) {
            throw new RuntimeException('Не удалось определить сообщение для возобновления анкеты.');
        }

        $contact->startDataCollection($nextField);

        ProcessDataCollectionQuestionJob::dispatch($sourceMessage->id, true, $contact->id, $nextField);

        return $nextField;
    }

    protected function resolveSourceMessage(Contact $contact): ?Message
    {
        return $contact->messages()
            ->whereNotNull('channel_id')
            ->whereNotNull('contact_identity_id')
            ->whereNotNull('external_chat_id')
            ->orderByDesc('id')
            ->first();
    }
}
