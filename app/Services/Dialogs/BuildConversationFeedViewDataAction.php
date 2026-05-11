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
                $mediaBadges = $this->resolveConversationMediaBadges($message);
                $mediaStateBadges = $this->resolveConversationMediaStateBadges($message);
                $isSystemMessage = $this->isConversationSystemMessage($message);

                return [
                    'id' => $message->id,
                    'sort_key' => $this->messageChronology->timestampAndIdSortKey($messageAt, $message->id),
                    'sort_at_iso' => $messageAt?->toIso8601String(),
                    'direction' => $message->direction,
                    'kind' => $message->message_kind ?? 'unknown',
                    'is_system_event' => $message->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT,
                    'is_system_message' => $isSystemMessage,
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
                    'has_media' => $mediaBadges !== [],
                    'media_badges' => $mediaBadges,
                    'media_state_badges' => $mediaStateBadges,
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

    protected function isConversationSystemMessage(Message $message): bool
    {
        return in_array($message->message_kind, [
            Message::KIND_INBOUND_SYSTEM_EVENT,
            Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
        ], true);
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

        if ($message->message_kind === Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE) {
            return 'Система';
        }

        if ($message->direction !== Message::DIRECTION_OUTBOUND) {
            return null;
        }

        if ($this->isBitrix24OpenLinesSender($message)) {
            return 'Bitrix24';
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
            return 'Системное уведомление';
        }

        if ($message->message_kind === Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE) {
            return 'Системное уведомление';
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

        if ($message->message_kind === Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE) {
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

        if ($this->isBitrix24OpenLinesSender($message)) {
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

    protected function isBitrix24OpenLinesSender(Message $message): bool
    {
        return $message->direction === Message::DIRECTION_OUTBOUND
            && $message->sent_by_system_code === Message::SENT_BY_SYSTEM_CODE_BITRIX24_OPENLINES;
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

        $mediaOnlyDisplayText = $this->resolveMediaOnlyConversationDisplayText($message);

        if ($mediaOnlyDisplayText !== null) {
            return $mediaOnlyDisplayText;
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

    protected function messageContainsMediaMetadata(Message $message): bool
    {
        return $this->resolveConversationMediaItems($message) !== [];
    }

    /**
     * @return list<string>
     */
    protected function resolveConversationMediaBadges(Message $message): array
    {
        $media = $this->resolveConversationMediaItems($message);

        if ($media === []) {
            return [];
        }

        $badges = [];

        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }

            $type = $this->formatMediaTypeLabel((string) data_get($item, 'type', ''));
            $fileName = $this->normalizeMediaBadgeText(data_get($item, 'file_name'));

            if ($fileName !== null) {
                $badges[] = sprintf('%s: %s', $type, Str::limit($fileName, 40, '...'));

                continue;
            }

            $badges[] = $type;
        }

        if ($badges === []) {
            return ['Медиа'];
        }

        if (count($badges) <= 3) {
            return $badges;
        }

        return [
            ...array_slice($badges, 0, 3),
            sprintf('+ ещё %d', count($badges) - 3),
        ];
    }

    protected function resolveMediaOnlyConversationDisplayText(Message $message): ?string
    {
        if (filled($message->text) || ! $this->messageContainsMediaMetadata($message)) {
            return null;
        }

        $media = data_get($message->raw_payload, 'media');

        if (! is_array($media) || $media === []) {
            return null;
        }

        $types = collect($media)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(fn (array $item): string => $this->formatMediaTypeLabel((string) data_get($item, 'type', '')))
            ->values();

        if ($types->isEmpty()) {
            return 'Медиа';
        }

        $counts = $types->countBy();

        if ($counts->count() === 1) {
            $label = (string) $counts->keys()->first();
            $count = (int) $counts->first();

            return $count > 1 ? sprintf('%s x%d', $label, $count) : $label;
        }

        return 'Медиа';
    }

    /**
     * @return list<array{label:string,tone:string}>
     */
    protected function resolveConversationMediaStateBadges(Message $message): array
    {
        $media = $this->resolveConversationMediaItems($message);

        if ($media === []) {
            return [];
        }

        $statusCounts = [];

        foreach ($media as $item) {
            $status = $this->resolveConversationMediaDownloadStatus($message, $item);

            if ($status === null || $status === Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADED) {
                continue;
            }

            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        if ($statusCounts === []) {
            return [];
        }

        $badges = [];

        foreach ([
            Message::MEDIA_DOWNLOAD_STATUS_FAILED,
            Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADING,
            Message::MEDIA_DOWNLOAD_STATUS_PENDING,
        ] as $status) {
            $count = $statusCounts[$status] ?? 0;

            if ($count < 1) {
                continue;
            }

            $label = $this->formatConversationMediaStateLabel($status);

            if ($count > 1) {
                $label .= ' x'.$count;
            }

            $badges[] = [
                'label' => $label,
                'tone' => $this->formatConversationMediaStateTone($status),
            ];
        }

        return $badges;
    }

    protected function formatMediaTypeLabel(string $type): string
    {
        return match (trim($type)) {
            'photo' => 'Фото',
            'video' => 'Видео',
            'audio' => 'Аудио',
            'voice' => 'Голосовое',
            'document' => 'Документ',
            'animation' => 'Анимация',
            'sticker' => 'Стикер',
            default => 'Медиа',
        };
    }

    protected function normalizeMediaBadgeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function resolveConversationMediaItems(Message $message): array
    {
        $media = data_get($message->raw_payload, 'media');

        if (! is_array($media) || $media === []) {
            return [];
        }

        return collect($media)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->values()
            ->all();
    }

    protected function resolveConversationMediaDownloadStatus(Message $message, array $item): ?string
    {
        $status = Message::normalizeMediaDownloadStatus(
            $this->normalizeMediaBadgeText(data_get($item, 'download_status'))
        );

        if ($status !== null) {
            return $status;
        }

        return $this->usesTelegramAccountMediaPlaceholderContract($message)
            ? Message::MEDIA_DOWNLOAD_STATUS_PENDING
            : null;
    }

    protected function usesTelegramAccountMediaPlaceholderContract(Message $message): bool
    {
        $channel = $message->channel ?? $message->dialog?->channel;

        return $channel instanceof Channel
            && $channel->platform === Channel::PLATFORM_TELEGRAM
            && $channel->isAccountConnection();
    }

    protected function formatConversationMediaStateLabel(string $status): string
    {
        return match ($status) {
            Message::MEDIA_DOWNLOAD_STATUS_PENDING => 'Ожидает загрузки',
            Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADING => 'Загружается',
            Message::MEDIA_DOWNLOAD_STATUS_FAILED => 'Ошибка загрузки',
            default => 'Статус не определён',
        };
    }

    protected function formatConversationMediaStateTone(string $status): string
    {
        return match ($status) {
            Message::MEDIA_DOWNLOAD_STATUS_FAILED => 'danger',
            Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADING => 'warning',
            Message::MEDIA_DOWNLOAD_STATUS_PENDING => 'gray',
            default => 'gray',
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
