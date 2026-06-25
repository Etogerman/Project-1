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
                $mediaItems = $this->resolveConversationMediaItems($message);
                $mediaBadges = $this->resolveConversationMediaBadges($message, $mediaItems);
                $mediaStateBadges = $this->resolveConversationMediaStateBadges($message, $mediaItems);
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
                    'display_text' => $this->resolveConversationDisplayText($message, $mediaItems),
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

        if ($this->isTelegramExternalAccountSender($message)) {
            return 'Telegram';
        }

        return match ($message->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => filled($message->sentByUser?->name)
                ? 'Оператор: '.$message->sentByUser->name
                : 'Оператор',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'Автоответчик',
            Message::SENT_BY_TYPE_COLLECTOR => 'Сбор данных',
            Message::SENT_BY_TYPE_SYSTEM => 'Система',
            default => $this->resolveLegacyConversationSenderLabel($message),
        };
    }

    protected function resolveLegacyConversationSenderLabel(Message $message): string
    {
        return match ($message->message_kind) {
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Оператор',
            Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE => 'Telegram',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответчик',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION,
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION,
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Сбор данных',
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

        if ($this->isTelegramExternalAccountSender($message)) {
            return 'success';
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

    protected function isTelegramExternalAccountSender(Message $message): bool
    {
        return $message->direction === Message::DIRECTION_OUTBOUND
            && $message->message_kind === Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE
            && $message->sent_by_system_code === Message::SENT_BY_SYSTEM_CODE_TELEGRAM_EXTERNAL_ACCOUNT;
    }

    /**
     * @param  list<array<string, mixed>>  $mediaItems
     */
    protected function resolveConversationDisplayText(Message $message, array $mediaItems): string
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

        $contactShareDisplayText = $this->resolveContactShareDisplayText($message);

        if ($contactShareDisplayText !== null) {
            return $contactShareDisplayText;
        }

        $mediaOnlyDisplayText = $this->resolveMediaOnlyConversationDisplayText($message, $mediaItems);

        if ($mediaOnlyDisplayText !== null) {
            return $mediaOnlyDisplayText;
        }

        return match ($message->message_kind) {
            Message::KIND_INBOUND_CONTACT_SHARE => 'Поделился номером телефона',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'Спасибо, номер получили.',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ответ оператора',
            Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE => 'Исходящее из Telegram',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос сбора данных',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Спасибо, данные сохранили.',
            default => 'Системное сообщение',
        };
    }

    protected function resolveContactShareDisplayText(Message $message): ?string
    {
        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
            return null;
        }

        $phoneNumber = $this->resolveSharedContactPhoneNumber($message);

        if (! filled($phoneNumber)) {
            return null;
        }

        return 'Поделился номером: '.$phoneNumber;
    }

    protected function resolveSharedContactPhoneNumber(Message $message): ?string
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $candidateContainers = [];

        foreach ([
            data_get($payload, 'message.contact'),
            data_get($payload, 'contact'),
            data_get($payload, 'body.contact'),
            data_get($payload, 'body'),
        ] as $container) {
            if (is_array($container)) {
                $candidateContainers[] = $container;
            }
        }

        foreach ([
            data_get($payload, 'attachments'),
            data_get($payload, 'body.attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                foreach ([
                    $attachment,
                    data_get($attachment, 'contact'),
                    data_get($attachment, 'payload'),
                    data_get($attachment, 'payload.contact'),
                ] as $container) {
                    if (is_array($container)) {
                        $candidateContainers[] = $container;
                    }
                }
            }
        }

        foreach ($candidateContainers as $container) {
            $phoneNumber = $this->normalizeSharedContactPhoneNumber(
                data_get($container, 'phone_number')
                ?? data_get($container, 'phone')
                ?? data_get($container, 'number')
                ?? data_get($container, 'contact.phone_number')
                ?? data_get($container, 'contact.phone')
                ?? data_get($container, 'payload.contact.phone_number')
                ?? data_get($container, 'payload.contact.phone')
            );

            if (filled($phoneNumber)) {
                return $phoneNumber;
            }

            $vcfPhoneNumber = $this->resolveSharedContactPhoneNumberFromVcf($container);

            if (filled($vcfPhoneNumber)) {
                return $vcfPhoneNumber;
            }
        }

        return null;
    }

    protected function normalizeSharedContactPhoneNumber(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $phoneNumber = trim((string) $value);

        return $phoneNumber !== '' ? $phoneNumber : null;
    }

    /**
     * @param  array<string, mixed>  $container
     */
    protected function resolveSharedContactPhoneNumberFromVcf(array $container): ?string
    {
        $vcfInfo = $this->normalizeSharedContactPhoneNumber(
            data_get($container, 'vcf_info')
            ?? data_get($container, 'payload.vcf_info')
        );

        if (! filled($vcfInfo)) {
            return null;
        }

        if (! preg_match('/^TEL(?:;[^:\r\n]+)*:([^\r\n]+)/mi', $vcfInfo, $matches)) {
            return null;
        }

        return $this->normalizeSharedContactPhoneNumber($matches[1] ?? null);
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<string>
     */
    protected function resolveConversationMediaBadges(Message $message, array $media): array
    {
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

    /**
     * @param  list<array<string, mixed>>  $media
     */
    protected function resolveMediaOnlyConversationDisplayText(Message $message, array $media): ?string
    {
        if (filled($message->text) || $media === []) {
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
     * @param  list<array<string, mixed>>  $media
     * @return list<array{label:string,tone:string}>
     */
    protected function resolveConversationMediaStateBadges(Message $message, array $media): array
    {
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
