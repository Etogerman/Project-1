<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24OpenLinesOperatorMessageData;
use App\Models\Bitrix24WebhookEvent;

class NormalizeBitrix24OpenLinesEventAction
{
    public const TYPE_OPERATOR_MESSAGE = 'operator_message';

    public const TYPE_SESSION_CLOSED = 'session_closed';

    public const TYPE_MESSAGE_UPDATED = 'message_updated';

    public const TYPE_MESSAGE_DELETED = 'message_deleted';

    public const TYPE_UNSUPPORTED = 'unsupported';

    /**
     * @return array{type: string, messages: list<Bitrix24OpenLinesOperatorMessageData>, chat_ids: list<string>}
     */
    public function handle(Bitrix24WebhookEvent $event): array
    {
        $eventName = mb_strtolower(trim((string) $event->event_name));

        if (in_array($eventName, [
            mb_strtolower('OnSendMessageCustom'),
            mb_strtolower('OnImConnectorMessageAdd'),
        ], true)) {
            return $this->normalizeOperatorMessages($event->payload);
        }

        if ($eventName === mb_strtolower('OnUpdateMessageCustom')) {
            return [
                'type' => self::TYPE_MESSAGE_UPDATED,
                'messages' => [],
                'chat_ids' => $this->resolveChatIds($event->payload),
            ];
        }

        if ($eventName === mb_strtolower('OnDeleteMessageCustom')) {
            return [
                'type' => self::TYPE_MESSAGE_DELETED,
                'messages' => [],
                'chat_ids' => $this->resolveChatIds($event->payload),
            ];
        }

        $sessionFinishEventNames = array_map(
            static fn (mixed $name): string => mb_strtolower(trim((string) $name)),
            (array) config('bitrix24.openlines.session_finish_event_names', ['OnSessionFinish']),
        );

        if (in_array($eventName, $sessionFinishEventNames, true)) {
            return [
                'type' => self::TYPE_SESSION_CLOSED,
                'messages' => [],
                'chat_ids' => $this->resolveChatIds($event->payload),
            ];
        }

        return [
            'type' => self::TYPE_UNSUPPORTED,
            'messages' => [],
            'chat_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{type: string, messages: list<Bitrix24OpenLinesOperatorMessageData>, chat_ids: list<string>}
     */
    private function normalizeOperatorMessages(array $payload): array
    {
        $container = $this->resolveContainer($payload);
        $connectorCode = $this->scalarString($container['CONNECTOR'] ?? $container['connector'] ?? null);
        $lineId = $this->scalarString($container['LINE'] ?? $container['line'] ?? null);
        $entries = $container['DATA'] ?? $container['data'] ?? $container['MESSAGES'] ?? $container['messages'] ?? [];

        if (! is_array($entries) || $entries === []) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines callback does not contain message entries.');
        }

        $messages = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new Bitrix24ApiException('Bitrix24 Open Lines callback contains an invalid message entry.');
            }

            $messages[] = new Bitrix24OpenLinesOperatorMessageData(
                connectorCode: $connectorCode,
                lineId: $lineId,
                chatId: $this->requiredScalarString(
                    data_get($entry, 'chat.id') ?? data_get($entry, 'chat.ID'),
                    'chat.id',
                ),
                bitrixMessageId: $this->resolveBitrixMessageId($entry),
                text: $this->requiredScalarString(
                    data_get($entry, 'message.text') ?? data_get($entry, 'message.TEXT') ?? data_get($entry, 'text'),
                    'message.text',
                ),
                im: $this->resolveImPayload($entry),
                rawPayload: $entry,
            );
        }

        return [
            'type' => self::TYPE_OPERATOR_MESSAGE,
            'messages' => $messages,
            'chat_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveContainer(array $payload): array
    {
        $container = $payload['data'] ?? $payload['DATA'] ?? $payload;

        return is_array($container) ? $container : $payload;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function resolveBitrixMessageId(array $entry): string
    {
        $candidate = data_get($entry, 'im.message_id')
            ?? data_get($entry, 'im.MESSAGE_ID')
            ?? data_get($entry, 'im.id')
            ?? data_get($entry, 'im.ID')
            ?? data_get($entry, 'message.id')
            ?? data_get($entry, 'message.ID');

        return $this->requiredScalarString($candidate, 'im.message_id');
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function resolveImPayload(array $entry): array
    {
        $im = $entry['im'] ?? $entry['IM'] ?? null;

        if (! is_array($im) || $im === []) {
            throw new Bitrix24ApiException('Bitrix24 Open Lines callback is missing `im` payload.');
        }

        return $im;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function resolveChatIds(array $payload): array
    {
        $container = $this->resolveContainer($payload);
        $candidates = [
            data_get($container, 'chat.id'),
            data_get($container, 'chat.ID'),
            data_get($container, 'CHAT.ID'),
            data_get($container, 'chat_id'),
            data_get($container, 'CHAT_ID'),
            data_get($payload, 'chat.id'),
            data_get($payload, 'chat.ID'),
            data_get($payload, 'CHAT.ID'),
            data_get($payload, 'chat_id'),
            data_get($payload, 'CHAT_ID'),
        ];

        $entries = $container['DATA'] ?? $container['data'] ?? $container['MESSAGES'] ?? $container['messages'] ?? [];

        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $candidates[] = data_get($entry, 'chat.id');
                $candidates[] = data_get($entry, 'chat.ID');
                $candidates[] = data_get($entry, 'CHAT.ID');
                $candidates[] = data_get($entry, 'chat_id');
                $candidates[] = data_get($entry, 'CHAT_ID');
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $candidate): string => $this->scalarString($candidate),
            $candidates,
        ))));
    }

    private function requiredScalarString(mixed $value, string $field): string
    {
        $normalized = $this->scalarString($value);

        if ($normalized === '') {
            throw new Bitrix24ApiException(sprintf(
                'Bitrix24 Open Lines callback is missing required field `%s`.',
                $field,
            ));
        }

        return $normalized;
    }

    private function scalarString(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }
}
