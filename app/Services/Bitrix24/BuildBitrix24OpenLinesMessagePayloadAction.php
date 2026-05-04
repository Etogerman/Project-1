<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesRouteData;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Contacts\ResolveContactDisplayNameAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\Dialogs\MessageChronology;

class BuildBitrix24OpenLinesMessagePayloadAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveContactDisplayNameAction $resolveContactDisplayNameAction,
        private readonly CollectBitrix24ContactPhonesAction $collectBitrix24ContactPhonesAction,
        private readonly ResolveBitrix24LiveChatKeyAction $resolveBitrix24LiveChatKeyAction,
        private readonly MessageChronology $messageChronology,
        private readonly BuildBitrix24OpenLinesExternalUserIdAction $buildExternalUserIdAction,
        private readonly ResolveBitrix24OpenLinesDialogBindingAction $resolveDialogBindingAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        Message $message,
        Bitrix24OpenLinesRouteData $route,
        bool $retryAfterSync = false,
        bool $applyLegacyFallbackSignature = false,
    ): array {
        $message->loadMissing([
            'dialog.channel',
            'dialog.currentContactIdentity',
            'contact.primaryIdentity',
            'contactIdentity',
            'sentByUser',
        ]);

        $dialog = $message->dialog()->firstOrFail();
        $rootContact = $this->resolveRootContactAction->handle($message->contact()->firstOrFail());
        $channel = $dialog->channel ?? $message->channel()->firstOrFail();
        $identity = $dialog->currentContactIdentity ?? $message->contactIdentity;
        $timestamp = $this->messageChronology->resolveSortAt($message);
        $text = $this->resolveMessageText($message, $channel, $applyLegacyFallbackSignature);
        $dialogBinding = $this->resolveDialogBindingAction->handle($dialog, $route);
        $chatKey = $dialogBinding?->connectorChatId ?? $this->resolveBitrix24LiveChatKeyAction->handle($dialog);
        $userId = $this->buildExternalUserIdAction->handle($channel, $identity?->external_user_id, $rootContact->id);
        $userName = $this->resolveContactDisplayNameAction->handle($rootContact, $dialog);
        $phones = $this->collectBitrix24ContactPhonesAction->handle($rootContact);

        $probePayload = $this->resolveExplicitContactProbePayload($retryAfterSync, $rootContact->bitrix24_contact_id);

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
                ] + $this->resolveOptionalUserPayload($rootContact->last_name, $phones[0] ?? null)
                    + ($probePayload['user'] ?? []),
                'message' => [
                    'id' => 'abrikosoff-message:'.$message->id,
                    'date' => $timestamp->timestamp,
                    'text' => $text,
                ] + ($probePayload['message'] ?? []),
            ]],
        ];
    }

    private function resolveMessageText(Message $message, Channel $channel, bool $applyLegacyFallbackSignature): string
    {
        if ($this->isTelegramBotStartedMessage($message, $channel)) {
            return 'Клиент запустил Telegram-бота';
        }

        if ($this->isMaxBotStartedMessage($message, $channel)) {
            return 'Клиент запустил MAX-бота';
        }

        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            return 'Клиент поделился номером телефона';
        }

        if ($message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return match ($message->system_event_code) {
                Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER => 'Система: Клиент заблокировал бота',
                Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER => 'Система: Клиент разблокировал бота',
                default => 'Система: Служебное событие канала',
            };
        }

        $text = trim((string) $message->text);

        if (! $applyLegacyFallbackSignature) {
            return $text;
        }

        return match ($message->message_kind) {
            Message::KIND_OUTBOUND_MANUAL_REPLY => $this->prefixLegacyFallbackText(
                $this->resolveManualReplySignaturePrefix($message),
                $text,
            ),
            Message::KIND_OUTBOUND_AUTO_REPLY => $this->prefixLegacyFallbackText('ℹ️ [Автоответ]', $text),
            default => $text,
        };
    }

    private function resolveManualReplySignaturePrefix(Message $message): string
    {
        $operatorName = $this->resolveOperatorName($message);

        return $operatorName === 'Оператор'
            ? 'ℹ️ [Оператор]'
            : sprintf('ℹ️ [Оператор %s]', $operatorName);
    }

    private function resolveOperatorName(Message $message): string
    {
        $name = trim((string) ($message->sentByUser?->name ?? ''));

        return $name !== '' ? $name : 'Оператор';
    }

    private function prefixLegacyFallbackText(string $prefix, string $text): string
    {
        return $text === ''
            ? $prefix
            : $prefix.' '.$text;
    }

    private function isTelegramBotStartedMessage(Message $message, Channel $channel): bool
    {
        if (
            $channel->platform !== Channel::PLATFORM_TELEGRAM
            || $message->direction !== Message::DIRECTION_INBOUND
            || $message->message_kind !== Message::KIND_INBOUND_USER
        ) {
            return false;
        }

        $text = trim((string) $message->text);

        return preg_match('/^\/start(?:@\S+)?(?:\s+.+)?$/u', $text) === 1;
    }

    private function isMaxBotStartedMessage(Message $message, Channel $channel): bool
    {
        return $channel->platform === Channel::PLATFORM_MAX
            && $message->direction === Message::DIRECTION_INBOUND
            && $message->message_kind === Message::KIND_INBOUND_USER
            && data_get($message->raw_payload, 'update_type') === 'bot_started';
    }

    /**
     * @return array<string, string>
     */
    private function resolveOptionalUserPayload(?string $lastName, ?string $phone): array
    {
        return array_filter([
            'last_name' => $this->nullableString($lastName),
            'phone' => $this->nullableString($phone),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array{
     *     user?: array<string, string>,
     *     message?: array<string, array<string, string>>
     * }
     */
    private function resolveExplicitContactProbePayload(bool $retryAfterSync, mixed $bitrix24ContactId): array
    {
        $contactId = $this->nullableString($bitrix24ContactId);

        if ($contactId === null || ! ctype_digit($contactId) || (int) $contactId <= 0) {
            return [];
        }

        $messageParams = [
            'crm_contact_id_probe' => $contactId,
        ];

        if ($retryAfterSync) {
            $messageParams['retry_after_sync_probe'] = 'Y';
        }

        return [
            'user' => [
                'crm_contact_id' => $contactId,
            ],
            'message' => [
                'params' => $messageParams,
            ],
        ];
    }
}
