<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class BuildConversationFeedViewDataAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
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
                    'direction' => $message->direction,
                    'kind' => $message->message_kind ?? 'unknown',
                    'dialog_id' => $message->dialog_id,
                    'has_dialog' => $message->dialog_id !== null,
                    'channel_label' => $this->resolveConversationChannelLabel($message),
                    'sender_label' => $this->resolveConversationSenderLabel($message),
                    'sender_type' => $message->sent_by_type,
                    'display_text' => $this->resolveConversationDisplayText($message),
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

    protected function resolveConversationDisplayText(Message $message): string
    {
        if (filled($message->text)) {
            return (string) $message->text;
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
