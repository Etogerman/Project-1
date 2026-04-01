<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Channel;
use App\Models\Message;

class BuildBitrix24TimelineCommentAction
{
    private const COMMENT_HEADER_TEMPLATE = 'Архив переписки Abrikosoff (%d/%d)';

    public function handle(Bitrix24HistoryExportChunkData $chunk): string
    {
        $body = implode(
            "\n\n",
            array_map(
                fn (Message $message): string => $this->buildMessageBlock($message),
                $chunk->messages,
            ),
        );

        return sprintf(self::COMMENT_HEADER_TEMPLATE, $chunk->sequence, $chunk->total)
            ."\n\n"
            .$body;
    }

    public function buildMessageBlock(Message $message): string
    {
        $sortAt = $message->received_at ?? $message->created_at;
        $timestamp = $sortAt?->copy()
            ->timezone(config('app.timezone'))
            ->format('d.m.Y H:i') ?? '—';

        return sprintf(
            '[%s] %s / %s'."\n".'%s',
            $timestamp,
            $this->resolveAuthorLabel($message),
            $this->resolveChannelLabel($message),
            $this->resolveMessageText($message),
        );
    }

    public function maxHeaderLength(): int
    {
        return mb_strlen(sprintf(self::COMMENT_HEADER_TEMPLATE, 999, 999)) + 2;
    }

    private function resolveAuthorLabel(Message $message): string
    {
        if ($message->direction === Message::DIRECTION_INBOUND) {
            return 'Клиент';
        }

        return match ($message->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => 'Оператор',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'Автоответ',
            Message::SENT_BY_TYPE_COLLECTOR => 'Анкета',
            Message::SENT_BY_TYPE_SYSTEM => 'Система',
            default => 'Система',
        };
    }

    private function resolveChannelLabel(Message $message): string
    {
        $platform = $message->channel?->platform;

        if (! is_string($platform) || $platform === '') {
            return 'Неизвестный канал';
        }

        return Channel::platformOptions()[$platform] ?? ucfirst($platform);
    }

    private function resolveMessageText(Message $message): string
    {
        if ($message->message_kind === Message::KIND_INBOUND_CONTACT_SHARE) {
            return 'Клиент поделился номером телефона';
        }

        return trim((string) $message->text);
    }
}
