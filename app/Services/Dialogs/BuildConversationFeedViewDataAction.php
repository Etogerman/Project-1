<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Message;
use App\Services\Messages\PrepareMessageContentAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuildConversationFeedViewDataAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
    ) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function handle(Collection $messages): array
    {
        return $messages
            ->map(function (Message $message): array {
                $messageAt = $this->resolveMessageSortAt($message);

                return [
                    'id' => $message->id,
                    'sort_key' => $this->messageChronology->timestampAndIdSortKey($messageAt, $message->id),
                    'sort_at_iso' => $messageAt?->toIso8601String(),
                    'direction' => $message->direction,
                    'kind' => $message->message_kind ?? 'unknown',
                    'is_system_event' => $message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT,
                    'dialog_id' => $message->dialog_id,
                    'has_dialog' => $message->dialog_id !== null,
                    'channel_label' => $this->resolveConversationChannelLabel($message),
                    'sender_label' => $this->resolveConversationSenderLabel($message),
                    'sender_type' => $message->sent_by_type,
                    'direction_label' => $this->resolveConversationDirectionLabel($message),
                    'direction_tone' => $this->resolveConversationDirectionTone($message),
                    'sender_tone' => $this->resolveConversationSenderTone($message),
                    'text_format' => Message::normalizeTextFormat($message->text_format),
                    'is_html' => $message->usesHtmlFormat(),
                    'display_text' => $this->resolveConversationDisplayText($message),
                    'formatted_html' => $this->resolveConversationFormattedHtml($message),
                    'html_source_text' => $this->resolveConversationHtmlSourceText($message),
                    'time_label' => $messageAt?->format('H:i') ?? '—',
                    'timestamp_label' => $messageAt?->format('H:i d.m.Y') ?? '—',
                    'date_key' => $messageAt?->format('Y-m-d') ?? 'unknown-date',
                    'date_label' => $this->formatConversationDateLabel($messageAt),
                    'is_inbound' => $message->direction === Message::DIRECTION_INBOUND,
                    'is_outbound' => $message->direction === Message::DIRECTION_OUTBOUND,
                ];
            })
            ->all();
    }

    public function resolveMessageSortAt(Message $message): ?Carbon
    {
        return $this->messageChronology->resolveSortAt($message);
    }

    protected function resolveConversationChannelLabel(Message $message): string
    {
        return $this->formatChannelLabel($message->channel ?? $message->dialog?->channel, 'Неизвестный канал');
    }

    protected function resolveConversationSenderLabel(Message $message): ?string
    {
        if (
            $message->direction === Message::DIRECTION_INBOUND
            && $message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT
        ) {
            return 'Система';
        }

        if ($message->direction !== Message::DIRECTION_OUTBOUND) {
            return null;
        }

        return match ($message->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => filled($message->sentByUser?->name)
                ? 'Оператор: '.$message->sentByUser->name
                : 'Оператор',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'Автоответчик',
            Message::SENT_BY_TYPE_COLLECTOR => 'Анкета',
            Message::SENT_BY_TYPE_SYSTEM => 'Система',
            default => $this->resolveLegacyConversationSenderLabel($message),
        };
    }

    protected function resolveLegacyConversationSenderLabel(Message $message): string
    {
        return match ($message->message_kind) {
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Оператор',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответчик',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Анкета',
            default => 'Система',
        };
    }

    protected function resolveConversationDirectionLabel(Message $message): string
    {
        if ($message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'Системное';
        }

        return $message->direction === Message::DIRECTION_OUTBOUND
            ? 'Исходящее'
            : 'Входящее';
    }

    protected function resolveConversationDirectionTone(Message $message): string
    {
        if ($message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'gray';
        }

        return $message->direction === Message::DIRECTION_OUTBOUND
            ? 'success'
            : 'info';
    }

    protected function resolveConversationSenderTone(Message $message): string
    {
        if ($message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'gray';
        }

        if ($message->direction !== Message::DIRECTION_OUTBOUND) {
            return 'primary';
        }

        return match ($message->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => 'success',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'warning',
            Message::SENT_BY_TYPE_COLLECTOR => 'primary',
            Message::SENT_BY_TYPE_SYSTEM => 'gray',
            default => 'gray',
        };
    }

    protected function resolveConversationDisplayText(Message $message): string
    {
        $telegramStartPayloadDisplayText = $this->resolveTelegramStartPayloadDisplayText($message);

        if ($telegramStartPayloadDisplayText !== null) {
            return $telegramStartPayloadDisplayText;
        }

        if (filled($message->text)) {
            return (string) $message->text;
        }

        $maxBotStartedDisplayText = $this->resolveMaxBotStartedDisplayText($message);

        if ($maxBotStartedDisplayText !== null) {
            return $maxBotStartedDisplayText;
        }

        $systemEventDisplayText = $this->resolveSystemEventDisplayText($message);

        if ($systemEventDisplayText !== null) {
            return $systemEventDisplayText;
        }

        return match ($message->message_kind) {
            Message::KIND_INBOUND_CONTACT_SHARE => 'Поделился номером телефона',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'Спасибо, номер получили.',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ответ оператора',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос анкеты',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Спасибо, данные сохранили.',
            default => 'Системное сообщение',
        };
    }

    protected function resolveSystemEventDisplayText(Message $message): ?string
    {
        if ($message->message_kind !== Message::KIND_INBOUND_SYSTEM_EVENT) {
            return null;
        }

        return match ($message->system_event_code) {
            Message::SYSTEM_EVENT_CODE_BOT_BLOCKED_BY_USER => 'Клиент заблокировал бота',
            Message::SYSTEM_EVENT_CODE_BOT_UNBLOCKED_BY_USER => 'Клиент разблокировал бота',
            default => 'Системное сообщение',
        };
    }

    protected function resolveConversationFormattedHtml(Message $message): ?string
    {
        if (! $message->usesHtmlFormat()) {
            return null;
        }

        if (! filled($message->source_text)) {
            return null;
        }

        $sanitizedHtml = $this->prepareMessageContentAction->sanitizeHtml((string) $message->source_text);

        return $sanitizedHtml !== ''
            ? $sanitizedHtml
            : null;
    }

    protected function resolveConversationHtmlSourceText(Message $message): ?string
    {
        if (! $message->usesHtmlFormat()) {
            return null;
        }

        return filled($message->source_text)
            ? (string) $message->source_text
            : null;
    }

    protected function resolveTelegramStartPayloadDisplayText(Message $message): ?string
    {
        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return null;
        }

        $platform = $message->channel?->platform ?? $message->dialog?->channel?->platform;

        if ($platform !== Channel::PLATFORM_TELEGRAM || ! filled($message->text)) {
            return null;
        }

        $normalizedText = trim((string) $message->text);

        if (! preg_match('/^\/start\s+(.+)$/u', $normalizedText, $matches)) {
            return null;
        }

        $payload = $this->resolveDisplayableBotStartedPayload($matches[1] ?? null);

        if ($payload === null) {
            return null;
        }

        return 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');
    }

    protected function resolveMaxBotStartedDisplayText(Message $message): ?string
    {
        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return null;
        }

        $platform = $message->channel?->platform ?? $message->dialog?->channel?->platform;

        if ($platform !== Channel::PLATFORM_MAX) {
            return null;
        }

        if (data_get($message->raw_payload, 'update_type') !== 'bot_started') {
            return null;
        }

        $payload = $this->resolveDisplayableBotStartedPayload(
            data_get($message->raw_payload, 'payload')
        );

        if ($payload === null) {
            return 'Открыл бота по диплинку';
        }

        return 'Открыл бота по диплинку: '.Str::limit($payload, 120, '...');
    }

    protected function resolveDisplayableBotStartedPayload(mixed $payload): ?string
    {
        if (! is_scalar($payload)) {
            return null;
        }

        $payload = trim((string) $payload);

        return $payload !== '' ? $payload : null;
    }

    protected function formatConversationDateLabel(?Carbon $messageAt): string
    {
        if (! $messageAt instanceof Carbon) {
            return '—';
        }

        $today = now()->startOfDay();
        $messageDate = $messageAt->copy()->startOfDay();

        if ($messageDate->equalTo($today)) {
            return 'Сегодня';
        }

        if ($messageDate->equalTo($today->copy()->subDay())) {
            return 'Вчера';
        }

        return $messageAt->format('d.m.Y');
    }

    protected function formatChannelLabel(?Channel $channel, string $fallback = '—'): string
    {
        if ($channel === null) {
            return $fallback;
        }

        $platformLabel = filled($channel->platform)
            ? (Channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: $fallback;
    }
}
