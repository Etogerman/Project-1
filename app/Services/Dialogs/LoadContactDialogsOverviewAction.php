<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DialogRouteStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\FieldDictionaryField;
use App\Models\Message;
use Illuminate\Support\Collection;

class LoadContactDialogsOverviewAction
{
    public function __construct(
        protected BuildConversationFeedViewDataAction $buildConversationFeedViewDataAction,
        protected ResolveDialogRouteStatusAction $resolveDialogRouteStatusAction,
        protected ResolveDialogStageAction $resolveDialogStageAction,
    ) {}

    /**
     * @return Collection<int, array{
     *     id:int,
     *     url:string,
     *     channel_label:string,
     *     route_status_label:string,
     *     route_status_tone:string,
     *     stage_label:string,
     *     stage_tone:string,
     *     messenger_name_label:string,
     *     phone_label:string,
     *     route_identity_label:string,
     *     external_chat_id_label:string,
     *     last_message_label:string,
     *     last_inbound_label:string,
     *     last_outbound_label:string,
     *     preview_text:string,
     *     preview_sender_label:?string,
     *     preview_sender_tone:?string,
     *     preview_media_state_badges:list<array{label:string,tone:string}>
     * }>
     */
    public function handle(Contact $contact): Collection
    {
        $stageOptionLabels = FieldDictionaryField::optionLabelsFor(FieldDictionaryField::ENTITY_DIALOG, 'stage');
        $dialogs = $contact->dialogs()
            ->with([
                'channel',
                'currentContactIdentity',
                'lastMessage.channel',
                'lastMessage.dialog.channel',
                'lastMessage.sentByUser',
            ])
            ->get();

        if ($dialogs->isEmpty()) {
            return collect();
        }

        $previewMessages = $dialogs
            ->pluck('lastMessage')
            ->filter(fn (mixed $message): bool => $message instanceof Message)
            ->values();
        $previewFeedByMessageId = collect(
            $this->buildConversationFeedViewDataAction->handle($previewMessages->values())
        )->mapWithKeys(function (array $feed): array {
            $messageIds = $feed['message_ids'] ?? [$feed['id'] ?? null];

            return collect(is_array($messageIds) ? $messageIds : [$messageIds])
                ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
                ->mapWithKeys(fn (mixed $id): array => [(int) $id => $feed])
                ->all();
        });

        return $dialogs
            ->map(function (Dialog $dialog) use ($contact, $previewFeedByMessageId, $stageOptionLabels): array {
                $previewMessage = $dialog->lastMessage;
                $previewFeed = $previewMessage instanceof Message
                    ? $previewFeedByMessageId->get($previewMessage->id)
                    : null;
                $previewSortAt = $previewMessage instanceof Message
                    ? $this->buildConversationFeedViewDataAction->resolveMessageSortAt($previewMessage)
                    : null;
                $sortAt = $previewSortAt ?? $dialog->last_message_at;
                $routeStatus = $this->resolveDialogRouteStatus($dialog);
                $stage = $this->resolveDialogStageAction->forAttributes(
                    currentStage: $dialog->stage,
                    contact: $contact,
                    phoneConfirmedAt: $dialog->phone_confirmed_at,
                );

                return [
                    'id' => $dialog->id,
                    'url' => DialogResource::getUrl('view', ['record' => $dialog]),
                    'channel_label' => $this->formatChannelLabel($dialog),
                    'route_status_label' => $routeStatus->label,
                    'route_status_tone' => $routeStatus->tone,
                    'stage_label' => FieldDictionaryField::optionLabelFrom($stageOptionLabels, $stage, Dialog::stageLabel($stage)),
                    'stage_tone' => Dialog::stageTone($stage),
                    'messenger_name_label' => $this->formatDialogMessengerNameLabel($dialog),
                    'phone_label' => $this->formatDialogPhoneLabel($dialog),
                    'route_identity_label' => $this->formatDialogRouteIdentityLabel($dialog),
                    'external_chat_id_label' => $dialog->external_chat_id ?: 'Не задан',
                    'last_message_label' => $this->formatDialogTimestamp($sortAt),
                    'last_inbound_label' => $this->formatDialogTimestamp($dialog->last_inbound_at),
                    'last_outbound_label' => $this->formatDialogTimestamp($dialog->last_outbound_at),
                    'preview_text' => filled($dialog->last_message_preview)
                        ? (string) $dialog->last_message_preview
                        : (is_array($previewFeed) ? (string) ($previewFeed['display_text'] ?? 'Сообщений ещё не было.') : 'Сообщений ещё не было.'),
                    'preview_sender_label' => $this->resolvePreviewSenderLabel($previewMessage, $previewFeed),
                    'preview_sender_tone' => $this->resolvePreviewSenderTone($previewMessage, $previewFeed),
                    'preview_media_state_badges' => $this->resolvePreviewMediaStateBadges($previewFeed),
                    'sort_at_timestamp' => $sortAt?->getTimestamp(),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $leftTimestamp = $left['sort_at_timestamp'];
                $rightTimestamp = $right['sort_at_timestamp'];

                if ($leftTimestamp === null && $rightTimestamp !== null) {
                    return 1;
                }

                if ($leftTimestamp !== null && $rightTimestamp === null) {
                    return -1;
                }

                $comparison = ($rightTimestamp ?? 0) <=> ($leftTimestamp ?? 0);

                if ($comparison !== 0) {
                    return $comparison;
                }

                return $right['id'] <=> $left['id'];
            })
            ->map(function (array $dialog): array {
                unset($dialog['sort_at_timestamp']);

                return $dialog;
            })
            ->values();
    }

    protected function resolvePreviewSenderLabel(?Message $previewMessage, mixed $previewFeed): ?string
    {
        if (! $previewMessage instanceof Message) {
            return null;
        }

        if ($previewMessage->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'Система';
        }

        if ($previewMessage->direction === Message::DIRECTION_INBOUND) {
            return 'Контакт';
        }

        if (is_array($previewFeed) && filled($previewFeed['sender_label'] ?? null)) {
            return (string) $previewFeed['sender_label'];
        }

        return 'Система';
    }

    protected function resolvePreviewSenderTone(?Message $previewMessage, mixed $previewFeed): ?string
    {
        if (! $previewMessage instanceof Message) {
            return null;
        }

        if ($previewMessage->message_kind === Message::KIND_INBOUND_SYSTEM_EVENT) {
            return 'gray';
        }

        if ($previewMessage->direction === Message::DIRECTION_INBOUND) {
            return 'info';
        }

        if (is_array($previewFeed) && filled($previewFeed['sender_tone'] ?? null)) {
            return (string) $previewFeed['sender_tone'];
        }

        return match ($previewMessage->sent_by_type) {
            Message::SENT_BY_TYPE_OPERATOR => 'success',
            Message::SENT_BY_TYPE_AUTO_REPLY => 'warning',
            Message::SENT_BY_TYPE_COLLECTOR => 'primary',
            Message::SENT_BY_TYPE_SYSTEM => 'gray',
            default => 'gray',
        };
    }

    /**
     * @return list<array{label:string,tone:string}>
     */
    protected function resolvePreviewMediaStateBadges(mixed $previewFeed): array
    {
        if (! is_array($previewFeed) || ! is_array($previewFeed['media_state_badges'] ?? null)) {
            return [];
        }

        return collect($previewFeed['media_state_badges'])
            ->filter(fn (mixed $badge): bool => is_array($badge) && filled($badge['label'] ?? null))
            ->map(fn (array $badge): array => [
                'label' => (string) $badge['label'],
                'tone' => filled($badge['tone'] ?? null) ? (string) $badge['tone'] : 'gray',
            ])
            ->values()
            ->all();
    }

    protected function resolveDialogRouteStatus(Dialog $dialog): DialogRouteStatusData
    {
        return $this->resolveDialogRouteStatusAction->handle($dialog);
    }

    protected function formatChannelLabel(Dialog $dialog): string
    {
        $channel = $dialog->channel;

        if ($channel === null) {
            return 'Неизвестный канал';
        }

        $platformLabel = filled($channel->platform)
            ? ($channel::platformOptions()[$channel->platform] ?? $channel->platform)
            : null;

        if (filled($channel->name) && filled($platformLabel)) {
            return sprintf('%s (%s)', $channel->name, $platformLabel);
        }

        return $channel->name ?: $platformLabel ?: 'Неизвестный канал';
    }

    protected function formatDialogPhoneLabel(Dialog $dialog): string
    {
        if (filled($dialog->confirmed_phone_raw)) {
            return (string) $dialog->confirmed_phone_raw;
        }

        if (filled($dialog->confirmed_phone_normalized)) {
            return (string) $dialog->confirmed_phone_normalized;
        }

        return 'Телефон в этом канале не подтвержден';
    }

    protected function formatDialogMessengerNameLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;

        if (filled($identity?->display_name)) {
            return trim((string) $identity->display_name);
        }

        if (filled($identity?->external_username)) {
            return '@'.ltrim((string) $identity->external_username, '@');
        }

        if (filled($identity?->external_user_id)) {
            return 'ID: '.$identity->external_user_id;
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return '—';
    }

    protected function formatDialogRouteIdentityLabel(Dialog $dialog): string
    {
        $identity = $dialog->currentContactIdentity;
        $parts = [];

        if (filled($identity?->external_user_id)) {
            $parts[] = 'ID: '.$identity->external_user_id;
        }

        if (filled($identity?->external_username)) {
            $parts[] = '@'.ltrim((string) $identity->external_username, '@');
        }

        if ($parts !== []) {
            return implode(' · ', $parts);
        }

        if ($dialog->current_contact_identity_id !== null) {
            return 'Identity #'.$dialog->current_contact_identity_id;
        }

        return 'Не задан';
    }

    protected function formatDialogTimestamp(mixed $timestamp): string
    {
        return $timestamp?->format('d.m.Y H:i') ?? '—';
    }
}
