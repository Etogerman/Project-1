<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24MessageExport;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\MessageChronology;
use Illuminate\Support\Collection;

class CollectBitrix24HistoryMessagesAction
{
    /**
     * @var list<string>
     */
    private const EXPORTABLE_MESSAGE_KINDS = [
        Message::KIND_INBOUND_USER,
        Message::KIND_INBOUND_CONTACT_SHARE,
        Message::KIND_OUTBOUND_AUTO_REPLY,
        Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
        Message::KIND_OUTBOUND_MANUAL_REPLY,
        Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
        Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
        Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION,
    ];

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly CollectBitrix24HistoryContactIdsAction $collectHistoryContactIdsAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @return Collection<int, Message>
     */
    public function handle(Contact|int $contact): Collection
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $contactIds = $this->collectHistoryContactIdsAction->handle($rootContact);

        $query = Message::query()
            ->with(['channel:id,platform'])
            ->whereIn('contact_id', $contactIds)
            ->whereIn('message_kind', self::EXPORTABLE_MESSAGE_KINDS)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('bitrix24_message_exports as bitrix24_message_exports')
                    ->whereColumn('bitrix24_message_exports.message_id', 'messages.id')
                    ->where('bitrix24_message_exports.export_status', Bitrix24MessageExport::STATUS_EXPORTED)
                    ->whereIn('bitrix24_message_exports.export_mode', [
                        Bitrix24MessageExport::MODE_HISTORY,
                        Bitrix24MessageExport::MODE_LIVE,
                    ]);
            });

        $this->messageChronology->applyOldestOrder($query);

        return $query->get()
            ->filter(function (Message $message): bool {
                if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
                    return true;
                }

                return trim((string) $message->text) !== '';
            })
            ->values();
    }
}
