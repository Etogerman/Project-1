<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\MessageChronology;

class BuildBitrix24OpenLinesMessagePayloadAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveBitrix24LiveChatKeyAction,
        private readonly MessageChronology $messageChronology,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Message $message, Bitrix24OpenLinesRouteData $route): array
    {
        $message->loadMissing([
            'dialog.channel',
            'dialog.currentContactIdentity',
            'contact.primaryIdentity',
            'contactIdentity',
        ]);

        $dialog = $message->dialog()->firstOrFail();
        $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());
        $channel = $dialog->channel ?? $message->channel()->firstOrFail();
        $identity = $dialog->currentContactIdentity ?? $message->contactIdentity;
        $timestamp = $this->messageChronology->resolveSortAt($message);
        $text = $this->resolveMessageText($message);
        $chatKey = $this->resolveBitrix24LiveChatKeyAction->handle($dialog);
        $userId = $this->resolveUserId($channel, $identity?->external_user_id, $rootContact->id);
        $userName = $rootContact->display_name;

        return [
            'CONNECTOR' => $route->connectorCode,
            'LINE' => $route->lineId,
            'MESSAGES' => [[
                'chat' => [
                    'id' => $chatKey,
                    'name' => $userName,
                ],
                'user' => [
                    'id' => $userId,
                    'name' => $userName,
                ],
                'message' => [
                    'id' => 'abrikosoff-message:'.$message->id,
                    'date' => $timestamp->timestamp,
                    'text' => $text,
                ],
            ]],
        ];
    }

    private function resolveMessageText(Message $message): string
    {
        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            return 'Клиент поделился номером телефона';
        }

        return trim((string) $message->text);
    }

    private function resolveUserId(Channel $channel, ?string $externalUserId, int $rootContactId): string
    {
        if (filled($externalUserId)) {
            return $channel->platform.':'.$externalUserId;
        }

        return 'contact:'.$rootContactId;
    }
}
