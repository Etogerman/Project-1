<?php

namespace App\Services\Dialogs;

use App\Filament\Resources\Contacts\ContactResource;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\MessageRevision;
use App\Services\Messages\AbRichTextHtmlRenderer;
use App\Services\Messages\PrepareMessageContentAction;
use App\Services\Messages\ResolveMessageMediaItemsAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class BuildConversationFeedViewDataAction
{
    public function __construct(
        private readonly MessageChronology $messageChronology,
        private readonly PrepareMessageContentAction $prepareMessageContentAction,
        private readonly ResolveMessageMediaItemsAction $resolveMessageMediaItemsAction,
        private readonly AbRichTextHtmlRenderer $abRichTextHtmlRenderer,
    ) {}

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    public function handle(Collection $messages): array
    {
        $this->loadConversationMediaRelations($messages);
        $messages = $this->loadConversationGroupSiblingMessages($messages);
        $forwardedIdentityIndex = $this->resolveConversationForwardedIdentityIndex($messages);
        $contactShareIdentityIndex = $this->resolveConversationContactShareIdentityIndex($messages);
        $replyMessageIndex = $this->resolveConversationReplyMessageIndex($messages);

        return $messages
            ->sort(fn (Message $left, Message $right): int => $this->compareConversationMessages($left, $right))
            ->groupBy(fn (Message $message): string => $this->resolveConversationItemKey($message))
            ->map(function (Collection $groupMessages, string $itemKey) use ($forwardedIdentityIndex, $contactShareIdentityIndex, $replyMessageIndex): array {
                /** @var Collection<int, Message> $groupMessages */
                $groupMessages = $groupMessages
                    ->sort(fn (Message $left, Message $right): int => $this->compareConversationMessages($left, $right))
                    ->values();

                /** @var Message $message */
                $message = $groupMessages->first();
                $messageAt = $this->resolveMessageSortAt($message);
                $mediaItems = $this->resolveConversationGroupMediaItems($groupMessages);
                $mediaItemViewData = $this->resolveConversationMediaItemViewData($message, $mediaItems);
                $mediaBadges = $this->resolveConversationMediaBadges($message, $mediaItems);
                $mediaStateBadges = $this->resolveConversationMediaStateBadges($message, $mediaItems);
                $displayText = $this->resolveConversationGroupDisplayText($message, $groupMessages, $mediaItems);
                $mediaOnlyDisplayText = $this->resolveMediaOnlyConversationDisplayText($message, $mediaItems);
                $isSystemMessage = $this->isConversationSystemMessage($message);
                $forwardedContext = $this->resolveConversationForwardedContext($message, $forwardedIdentityIndex);
                $replyContext = $this->resolveConversationReplyContext($message, $replyMessageIndex);
                $contactShareContext = $this->resolveConversationContactShareContext($message, $contactShareIdentityIndex);
                $buttonContext = $this->resolveConversationButtonContext($message);
                $editContext = $this->resolveConversationEditContext($groupMessages);
                $removalContext = $this->resolveConversationRemovalContext($groupMessages);

                return [
                    'id' => $message->id,
                    'item_key' => $itemKey,
                    'message_ids' => $groupMessages
                        ->pluck('id')
                        ->map(static fn (mixed $id): int => (int) $id)
                        ->values()
                        ->all(),
                    'is_grouped' => $groupMessages->count() > 1,
                    'provider_group_key' => $this->normalizeConversationProviderGroupKey($message->provider_group_key),
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
                    'forwarded_label' => data_get($forwardedContext, 'label'),
                    'forwarded_context' => $forwardedContext,
                    'reply_context' => $replyContext,
                    'contact_share_context' => $contactShareContext,
                    'button_context' => $buttonContext,
                    'is_edited' => $editContext['is_edited'],
                    'edited_label' => $editContext['label'],
                    'edit_history' => $editContext['history'],
                    'is_removed' => $removalContext['is_removed'],
                    'removed_label' => $removalContext['label'],
                    'removed_at_iso' => $removalContext['removed_at_iso'],
                    'direction_label' => $this->resolveConversationDirectionLabel($message),
                    'direction_tone' => $this->resolveConversationDirectionTone($message),
                    'sender_tone' => $this->resolveConversationSenderTone($message),
                    'text_format' => Message::normalizeTextFormat($message->text_format),
                    'is_html' => $message->usesHtmlFormat(),
                    'display_text' => $displayText,
                    'is_media_only_display_text' => $mediaOnlyDisplayText !== null && $displayText === $mediaOnlyDisplayText,
                    'formatted_html' => $this->resolveConversationGroupFormattedHtml($groupMessages),
                    'html_source_text' => $this->resolveConversationGroupHtmlSourceText($groupMessages),
                    'has_media' => $mediaBadges !== [],
                    'media_badges' => $mediaBadges,
                    'media_items' => $mediaItemViewData,
                    'media_state_badges' => $mediaStateBadges,
                    'time_label' => $messageAt?->format('H:i') ?? '—',
                    'timestamp_label' => $messageAt?->format('H:i d.m.Y') ?? '—',
                    'date_key' => $messageAt?->format('Y-m-d') ?? 'unknown-date',
                    'date_label' => $this->formatConversationDateLabel($messageAt),
                    'is_inbound' => $message->direction === Message::DIRECTION_INBOUND,
                    'is_outbound' => $message->direction === Message::DIRECTION_OUTBOUND,
                ];
            })
            ->values()
            ->all();
    }

    protected function loadConversationGroupSiblingMessages(Collection $messages): Collection
    {
        if ($messages->isEmpty()) {
            return $messages->values();
        }

        $groupScopes = $messages
            ->map(fn (Message $message): ?array => $this->resolveConversationGroupScope($message))
            ->filter()
            ->unique(fn (array $scope): string => implode('|', array_map(
                static fn (mixed $value): string => $value === null ? '<null>' : (string) $value,
                $scope,
            )))
            ->values();

        if ($groupScopes->isEmpty()) {
            return $messages->values();
        }

        $siblingMessages = Message::query()
            ->where(function ($query) use ($groupScopes): void {
                foreach ($groupScopes as $scope) {
                    $query->orWhere(function ($nested) use ($scope): void {
                        if ($scope['dialog_id'] !== null) {
                            $nested->where('dialog_id', $scope['dialog_id']);
                        } else {
                            $nested
                                ->whereNull('dialog_id')
                                ->where('external_chat_id', $scope['external_chat_id']);
                        }

                        $nested
                            ->where('channel_id', $scope['channel_id'])
                            ->where('direction', $scope['direction'])
                            ->where('provider_group_key', $scope['provider_group_key']);
                    });
                }
            })
            ->with(['channel', 'dialog.channel', 'sentByUser', 'attachments', 'revisions'])
            ->get();

        return $messages
            ->keyBy('id')
            ->union($siblingMessages->keyBy('id'))
            ->values();
    }

    /**
     * @return array{dialog_id:int|null,external_chat_id:string|null,channel_id:int,direction:string,provider_group_key:string}|null
     */
    protected function resolveConversationGroupScope(Message $message): ?array
    {
        $providerGroupKey = $this->normalizeConversationProviderGroupKey($message->provider_group_key);

        if ($providerGroupKey === null || $message->channel_id === null || blank($message->direction)) {
            return null;
        }

        if ($message->dialog_id === null && blank($message->external_chat_id)) {
            return null;
        }

        return [
            'dialog_id' => $message->dialog_id === null ? null : (int) $message->dialog_id,
            'external_chat_id' => $message->dialog_id === null ? (string) $message->external_chat_id : null,
            'channel_id' => (int) $message->channel_id,
            'direction' => (string) $message->direction,
            'provider_group_key' => $providerGroupKey,
        ];
    }

    protected function resolveConversationItemKey(Message $message): string
    {
        $scope = $this->resolveConversationGroupScope($message);

        if ($scope === null) {
            return 'message:'.$message->id;
        }

        return 'group:'.sha1(implode('|', array_map(
            static fn (mixed $value): string => $value === null ? '<null>' : (string) $value,
            $scope,
        )));
    }

    protected function compareConversationMessages(Message $left, Message $right): int
    {
        return $this->messageChronology->compareSortTuple(
            $this->resolveMessageSortAt($left),
            $left->id,
            $this->resolveMessageSortAt($right),
            $right->id,
        );
    }

    protected function loadConversationMediaRelations(Collection $messages): void
    {
        if ($messages->isEmpty() || ! method_exists($messages, 'loadMissing')) {
            return;
        }

        $messages->loadMissing(['attachments', 'revisions']);
    }

    /**
     * @param  Collection<int, Message>  $groupMessages
     * @return array{is_edited: bool, label: ?string, history: list<array<string, mixed>>}
     */
    protected function resolveConversationEditContext(Collection $groupMessages): array
    {
        $latestEditedAt = $groupMessages
            ->map(fn (Message $message): ?Carbon => $message->edited_at)
            ->filter()
            ->sortBy(fn (Carbon $editedAt): int => $editedAt->getTimestamp())
            ->last();

        $history = $groupMessages
            ->flatMap(fn (Message $message): Collection => $message->revisions)
            ->filter(fn (MessageRevision $revision): bool => $revision->revision_type === MessageRevision::TYPE_EDIT)
            ->sort(function (MessageRevision $left, MessageRevision $right): int {
                $byEditedAt = ($left->provider_edited_at?->getTimestamp() ?? 0) <=> ($right->provider_edited_at?->getTimestamp() ?? 0);

                return $byEditedAt !== 0 ? $byEditedAt : $left->id <=> $right->id;
            })
            ->map(function (MessageRevision $revision): array {
                return [
                    'id' => $revision->id,
                    'label' => $revision->provider_edited_at?->format('H:i:s d.m.Y') ?? 'время неизвестно',
                    'previous_text' => $this->formatRevisionText($revision->previous_text),
                    'new_text' => $this->formatRevisionText($revision->new_text),
                ];
            })
            ->values()
            ->all();

        return [
            'is_edited' => $latestEditedAt instanceof Carbon || $history !== [],
            'label' => $latestEditedAt instanceof Carbon
                ? 'изменено '.$latestEditedAt->format('H:i:s')
                : ($history !== [] ? 'изменено' : null),
            'history' => $history,
        ];
    }

    /**
     * @param  Collection<int, Message>  $groupMessages
     * @return array{is_removed: bool, label: ?string, removed_at_iso: ?string}
     */
    protected function resolveConversationRemovalContext(Collection $groupMessages): array
    {
        $latestRemovedAt = $groupMessages
            ->map(fn (Message $message): ?Carbon => $message->removed_at)
            ->filter()
            ->sortBy(fn (Carbon $removedAt): int => $removedAt->getTimestamp())
            ->last();

        return [
            'is_removed' => $latestRemovedAt instanceof Carbon,
            'label' => $latestRemovedAt instanceof Carbon ? 'удалено '.$latestRemovedAt->format('H:i') : null,
            'removed_at_iso' => $latestRemovedAt instanceof Carbon ? $latestRemovedAt->toIso8601String() : null,
        ];
    }

    protected function formatRevisionText(?string $text): string
    {
        $normalized = trim((string) $text);

        return $normalized !== '' ? $normalized : 'Без текста';
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

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<string, Collection<int, ContactIdentity>>
     */
    protected function resolveConversationForwardedIdentityIndex(Collection $messages): Collection
    {
        if ($messages->isEmpty()) {
            return collect();
        }

        if (method_exists($messages, 'loadMissing')) {
            $messages->loadMissing('channel');
        }

        $lookupRows = $messages
            ->map(function (Message $message): ?array {
                $senderUserId = $this->resolveConversationForwardedSenderUserId($message);
                $source = $this->resolveConversationForwardedSource($message);
                $platform = $this->normalizeMediaBadgeText(data_get($source, 'provider') ?? $message->channel?->platform);

                if ($senderUserId === null || $platform === null) {
                    return null;
                }

                return [
                    'platform' => $platform,
                    'external_user_id' => $senderUserId,
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => $this->forwardedIdentityIndexKey(
                $row['platform'],
                $row['external_user_id'],
            ))
            ->values();

        if ($lookupRows->isEmpty()) {
            return collect();
        }

        return ContactIdentity::query()
            ->with(['contact', 'channel'])
            ->whereIn('platform', $lookupRows->pluck('platform')->unique()->values()->all())
            ->whereIn('external_user_id', $lookupRows->pluck('external_user_id')->unique()->values()->all())
            ->get()
            ->groupBy(fn (ContactIdentity $identity): string => $this->forwardedIdentityIndexKey(
                (string) $identity->platform,
                (string) $identity->external_user_id,
            ));
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<string, Collection<int, ContactIdentity>>
     */
    protected function resolveConversationContactShareIdentityIndex(Collection $messages): Collection
    {
        if ($messages->isEmpty()) {
            return collect();
        }

        if (method_exists($messages, 'loadMissing')) {
            $messages->loadMissing('channel');
        }

        $lookupRows = $messages
            ->map(function (Message $message): ?array {
                if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
                    return null;
                }

                $sharedContactUserId = $this->resolveSharedContactUserId($message);
                $platform = $this->normalizeMediaBadgeText($message->channel?->platform);

                if ($sharedContactUserId === null || $platform === null) {
                    return null;
                }

                return [
                    'platform' => $platform,
                    'external_user_id' => $sharedContactUserId,
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => $this->forwardedIdentityIndexKey(
                $row['platform'],
                $row['external_user_id'],
            ))
            ->values();

        if ($lookupRows->isEmpty()) {
            return collect();
        }

        return ContactIdentity::query()
            ->with(['contact', 'channel'])
            ->whereIn('platform', $lookupRows->pluck('platform')->unique()->values()->all())
            ->whereIn('external_user_id', $lookupRows->pluck('external_user_id')->unique()->values()->all())
            ->get()
            ->groupBy(fn (ContactIdentity $identity): string => $this->forwardedIdentityIndexKey(
                (string) $identity->platform,
                (string) $identity->external_user_id,
            ));
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return Collection<string, Message>
     */
    protected function resolveConversationReplyMessageIndex(Collection $messages): Collection
    {
        if ($messages->isEmpty()) {
            return collect();
        }

        $lookupRows = $messages
            ->map(function (Message $message): ?array {
                $originalMessageId = $this->resolveConversationReplyOriginalMessageId($message);
                $externalChatId = $this->normalizeMediaBadgeText($message->external_chat_id);

                if ($message->channel_id === null || $externalChatId === null || $originalMessageId === null) {
                    return null;
                }

                return [
                    'channel_id' => (int) $message->channel_id,
                    'external_chat_id' => $externalChatId,
                    'external_message_id' => $originalMessageId,
                ];
            })
            ->filter()
            ->unique(fn (array $row): string => $this->replyMessageIndexKey(
                $row['channel_id'],
                $row['external_chat_id'],
                $row['external_message_id'],
            ))
            ->values();

        if ($lookupRows->isEmpty()) {
            return collect();
        }

        $localMessages = Message::query()
            ->whereIn('channel_id', $lookupRows->pluck('channel_id')->unique()->values()->all())
            ->whereIn('external_chat_id', $lookupRows->pluck('external_chat_id')->unique()->values()->all())
            ->whereIn('external_message_id', $lookupRows->pluck('external_message_id')->unique()->values()->all())
            ->get(['id', 'channel_id', 'external_chat_id', 'external_message_id', 'text']);

        return $localMessages
            ->groupBy(fn (Message $message): string => $this->replyMessageIndexKey(
                (int) $message->channel_id,
                (string) $message->external_chat_id,
                (string) $message->external_message_id,
            ))
            ->map(fn (Collection $messages): ?Message => $messages
                ->sortByDesc(fn (Message $message): int => (int) $message->id)
                ->first())
            ->filter();
    }

    /**
     * @param  Collection<string, Collection<int, ContactIdentity>>  $forwardedIdentityIndex
     * @return array<string, mixed>|null
     */
    protected function resolveConversationForwardedContext(
        Message $message,
        Collection $forwardedIdentityIndex,
    ): ?array {
        $source = $this->resolveConversationForwardedSource($message);

        if ($source === null) {
            return null;
        }

        $sender = data_get($source, 'sender');
        $senderName = is_array($sender) ? $this->resolveProviderSenderName($sender) : null;
        $senderUserId = is_array($sender) ? $this->resolveProviderSenderUserId($sender) : null;
        $provider = Str::lower((string) $this->normalizeMediaBadgeText(data_get($source, 'provider') ?? $message->channel?->platform));
        $senderUsername = $provider === Channel::PLATFORM_TELEGRAM && is_array($sender)
            ? $this->resolveProviderSenderUsername($sender)
            : null;
        $originalMessageId = $this->normalizeMediaBadgeText(data_get($source, 'original_message_id'));
        $contactIdentity = $this->resolveForwardedContactIdentity($message, $senderUserId, $forwardedIdentityIndex);
        $contactLabel = $this->formatForwardedContactLabel($contactIdentity);
        $providerUserIdLabel = match ($provider) {
            Channel::PLATFORM_MAX => 'MAX user_id',
            Channel::PLATFORM_TELEGRAM => 'Telegram user_id',
            default => 'ID отправителя',
        };

        $details = collect([
            [
                'label' => $providerUserIdLabel,
                'value' => $senderUserId,
            ],
            [
                'label' => 'Telegram username',
                'value' => $senderUsername,
            ],
            [
                'label' => 'AB контакт',
                'value' => $contactLabel,
                'tone' => $contactIdentity === null ? 'warning' : 'success',
            ],
            [
                'label' => 'Оригинал',
                'value' => $originalMessageId,
            ],
        ])
            ->filter(fn (array $detail): bool => filled($detail['value'] ?? null))
            ->values()
            ->all();

        return [
            'label' => $senderName !== null
                ? 'Переслано от '.$senderName
                : ($senderUserId !== null ? 'Переслано от '.$providerUserIdLabel.' '.$senderUserId : 'Пересланное сообщение'),
            'sender_name' => $senderName,
            'sender_user_id' => $senderUserId,
            'sender_username' => $senderUsername,
            'contact_found' => $contactIdentity !== null,
            'contact_label' => $contactLabel,
            'contact_id' => $contactIdentity?->contact_id,
            'contact_url' => $this->resolveForwardedContactUrl($contactIdentity),
            'original_message_id' => $originalMessageId,
            'details' => $details,
        ];
    }

    /**
     * @param  Collection<string, Message>  $replyMessageIndex
     * @return array<string, mixed>|null
     */
    protected function resolveConversationReplyContext(Message $message, Collection $replyMessageIndex): ?array
    {
        $source = $this->resolveConversationReplySource($message);

        if ($source === null) {
            return null;
        }

        $originalMessageId = $this->normalizeMediaBadgeText(data_get($source, 'original_message_id'));
        $externalChatId = $this->normalizeMediaBadgeText($message->external_chat_id);
        $linkedMessage = data_get($source, 'message');
        $previewText = is_array($linkedMessage)
            ? $this->resolveConversationReplyPreviewText($linkedMessage)
            : null;

        $localMessage = null;

        if ($message->channel_id !== null && $externalChatId !== null && $originalMessageId !== null) {
            $candidate = $replyMessageIndex->get($this->replyMessageIndexKey(
                (int) $message->channel_id,
                $externalChatId,
                $originalMessageId,
            ));

            $localMessage = $candidate instanceof Message ? $candidate : null;
        }

        if ($previewText === null && $localMessage instanceof Message) {
            $previewText = $this->normalizeMediaBadgeText($localMessage->text);
        }

        return [
            'label' => 'Ответ на сообщение',
            'original_message_id' => $originalMessageId,
            'local_message_id' => $localMessage?->id,
            'has_local_message' => $localMessage instanceof Message,
            'preview_text' => $previewText ?? 'Сообщение без доступного текста',
        ];
    }

    /**
     * @param  Collection<string, Collection<int, ContactIdentity>>  $contactShareIdentityIndex
     * @return array<string, mixed>|null
     */
    protected function resolveConversationContactShareContext(
        Message $message,
        Collection $contactShareIdentityIndex,
    ): ?array {
        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
            return null;
        }

        $name = $this->resolveSharedContactName($message);
        $phoneNumber = $this->resolveSharedContactPhoneNumber($message);
        $sharedContactUserId = $this->resolveSharedContactUserId($message);
        $contactIdentity = $this->resolveContactShareContactIdentity($message, $sharedContactUserId, $contactShareIdentityIndex);
        $contactLabel = $this->formatForwardedContactLabel($contactIdentity);
        $providerUserIdLabel = match (Str::lower((string) $this->normalizeMediaBadgeText($message->channel?->platform))) {
            Channel::PLATFORM_MAX => 'MAX user_id',
            Channel::PLATFORM_TELEGRAM => 'Telegram user_id',
            default => 'ID контакта',
        };

        $details = collect([
            [
                'label' => 'Имя',
                'value' => $name,
            ],
            [
                'label' => 'Телефон',
                'value' => $phoneNumber,
            ],
            [
                'label' => $providerUserIdLabel,
                'value' => $sharedContactUserId,
            ],
            [
                'label' => 'AB контакт',
                'value' => $sharedContactUserId === null ? null : $contactLabel,
                'tone' => $contactIdentity === null ? 'warning' : 'success',
            ],
        ])
            ->filter(fn (array $detail): bool => filled($detail['value'] ?? null))
            ->values()
            ->all();

        return [
            'label' => filled($phoneNumber)
                ? 'Поделился номером'
                : 'Поделился контактом',
            'name' => $name,
            'phone_number' => $phoneNumber,
            'shared_contact_user_id' => $sharedContactUserId,
            'contact_found' => $contactIdentity !== null,
            'contact_label' => $contactLabel,
            'contact_id' => $contactIdentity?->contact_id,
            'details' => $details,
        ];
    }

    /**
     * @return array{label:string,rows:list<list<array<string, mixed>>>}|null
     */
    protected function resolveConversationButtonContext(Message $message): ?array
    {
        if ($message->direction !== Message::DIRECTION_OUTBOUND) {
            return null;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $rows = $this->resolveProviderConversationButtonRows($payload);

        if ($rows === []) {
            $rows = $this->normalizeConversationButtonRows(data_get($payload, 'v3.buttons.rows'));
        }

        if ($rows === []) {
            return null;
        }

        return [
            'label' => 'Отправленные кнопки',
            'rows' => $rows,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<list<array<string, mixed>>>
     */
    protected function resolveProviderConversationButtonRows(array $payload): array
    {
        $attachmentSets = [
            data_get($payload, 'message.body.attachments'),
            data_get($payload, 'message.attachments'),
            data_get($payload, 'attachments'),
            data_get($payload, 'result.attachments'),
            data_get($payload, 'request.attachments'),
        ];

        foreach ($attachmentSets as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $attachment) {
                if (! is_array($attachment)) {
                    continue;
                }

                $type = Str::lower((string) $this->normalizeMediaBadgeText($attachment['type'] ?? null));

                if (! in_array($type, ['inline_keyboard', 'keyboard'], true)) {
                    continue;
                }

                $rows = $this->normalizeConversationButtonRows(
                    data_get($attachment, 'payload.buttons')
                        ?? data_get($attachment, 'payload.keyboard')
                        ?? data_get($attachment, 'buttons')
                );

                if ($rows !== []) {
                    return $rows;
                }
            }
        }

        return [];
    }

    /**
     * @return list<list<array<string, mixed>>>
     */
    protected function normalizeConversationButtonRows(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->map(function (mixed $row): array {
                $buttons = $this->isConversationButtonPayload($row)
                    ? [$row]
                    : (is_array($row) ? $row : []);

                return collect($buttons)
                    ->map(fn (mixed $button): ?array => is_array($button)
                        ? $this->normalizeConversationButton($button)
                        : null)
                    ->filter()
                    ->values()
                    ->all();
            })
            ->filter(fn (array $row): bool => $row !== [])
            ->values()
            ->all();
    }

    protected function isConversationButtonPayload(mixed $value): bool
    {
        return is_array($value) && array_key_exists('text', $value);
    }

    /**
     * @param  array<string, mixed>  $button
     * @return array<string, mixed>|null
     */
    protected function normalizeConversationButton(array $button): ?array
    {
        $text = $this->normalizeMediaBadgeText($button['text'] ?? null);

        if ($text === null) {
            return null;
        }

        $type = Str::lower((string) ($this->normalizeMediaBadgeText($button['type'] ?? null) ?? 'button'));
        $url = $this->normalizeMediaBadgeText($button['url'] ?? null);

        return [
            'text' => $text,
            'type' => $type,
            'type_label' => $this->formatConversationButtonTypeLabel($type, $url),
            'url' => $url,
        ];
    }

    protected function formatConversationButtonTypeLabel(string $type, ?string $url): string
    {
        if ($url !== null || $type === 'link') {
            return 'Ссылка';
        }

        return match ($type) {
            'request_contact',
            'request_phone' => 'Запрос телефона',
            'text' => 'Ответ',
            default => 'Кнопка',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveConversationForwardedLink(Message $message): ?array
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $link = data_get($payload, 'message.link');

        if (! is_array($link)) {
            return null;
        }

        $linkType = $this->normalizeMediaBadgeText(data_get($link, 'type'));

        return Str::lower((string) $linkType) === 'forward'
            ? $link
            : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveConversationForwardedSource(Message $message): ?array
    {
        return $this->resolveMaxConversationForwardedSource($message)
            ?? $this->resolveTelegramConversationForwardedSource($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMaxConversationForwardedSource(Message $message): ?array
    {
        $link = $this->resolveConversationForwardedLink($message);

        if ($link === null) {
            return null;
        }

        return [
            'provider' => Channel::PLATFORM_MAX,
            'sender' => data_get($link, 'sender'),
            'message' => data_get($link, 'message'),
            'original_message_id' => data_get($link, 'message.mid'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveTelegramConversationForwardedSource(Message $message): ?array
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $telegramMessage = data_get($payload, 'message');

        if (! is_array($telegramMessage)) {
            return $this->resolveTdlibTelegramForwardedSource($payload);
        }

        $origin = data_get($telegramMessage, 'forward_origin');
        $sender = null;

        if (is_array($origin)) {
            $originType = Str::lower((string) $this->normalizeMediaBadgeText(data_get($origin, 'type')));

            if ($originType === 'user' && is_array(data_get($origin, 'sender_user'))) {
                $sender = data_get($origin, 'sender_user');
            } elseif (filled(data_get($origin, 'sender_user_name'))) {
                $sender = ['name' => data_get($origin, 'sender_user_name')];
            } elseif (is_array(data_get($origin, 'chat'))) {
                $sender = data_get($origin, 'chat');
            } elseif (filled(data_get($origin, 'author_signature'))) {
                $sender = ['name' => data_get($origin, 'author_signature')];
            }
        }

        if (! is_array($sender) && is_array(data_get($telegramMessage, 'forward_from'))) {
            $sender = data_get($telegramMessage, 'forward_from');
        }

        if (! is_array($sender) && is_array(data_get($telegramMessage, 'forward_from_chat'))) {
            $sender = data_get($telegramMessage, 'forward_from_chat');
        }

        if (! is_array($sender) && filled(data_get($telegramMessage, 'forward_sender_name'))) {
            $sender = ['name' => data_get($telegramMessage, 'forward_sender_name')];
        }

        if (! is_array($sender)) {
            return null;
        }

        return [
            'provider' => Channel::PLATFORM_TELEGRAM,
            'sender' => $sender,
            'message' => $telegramMessage,
            'original_message_id' => data_get($telegramMessage, 'forward_from_message_id'),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    protected function resolveTdlibTelegramForwardedSource(array $payload): ?array
    {
        $forwardInfo = data_get($payload, 'forward_info');

        if (! is_array($forwardInfo)) {
            return null;
        }

        $origin = data_get($forwardInfo, 'origin');
        $sender = null;

        if (is_array($origin)) {
            $originType = Str::lower((string) $this->normalizeMediaBadgeText(data_get($origin, '_')));

            $sender = match ($originType) {
                'messageoriginuser' => [
                    'id' => data_get($origin, 'sender_user_id'),
                    'username' => data_get($origin, 'sender_username')
                        ?? data_get($origin, 'sender_user.username')
                        ?? data_get($forwardInfo, 'sender_username')
                        ?? data_get($forwardInfo, 'sender_user.username')
                        ?? data_get($forwardInfo, 'from_message_sender.username')
                        ?? $this->resolveTelegramPayloadUserUsername($payload, data_get($origin, 'sender_user_id')),
                ],
                'messageoriginhiddenuser' => [
                    'name' => data_get($origin, 'sender_name'),
                ],
                'messageoriginchat' => [
                    'id' => data_get($origin, 'sender_chat_id'),
                    'name' => data_get($origin, 'author_signature'),
                    'username' => data_get($origin, 'sender_username')
                        ?? data_get($origin, 'sender_chat.username')
                        ?? data_get($forwardInfo, 'sender_chat.username')
                        ?? data_get($forwardInfo, 'from_message_sender.username'),
                ],
                'messageoriginchannel' => [
                    'id' => data_get($origin, 'chat_id'),
                    'name' => data_get($origin, 'author_signature'),
                    'username' => data_get($origin, 'chat.username')
                        ?? data_get($forwardInfo, 'chat.username')
                        ?? data_get($forwardInfo, 'from_message_sender.username'),
                ],
                default => null,
            };
        }

        if (! is_array($sender)) {
            $senderUserId = data_get($forwardInfo, 'from_message_sender.user_id')
                ?? data_get($forwardInfo, 'from_message_sender.chat_id')
                ?? data_get($forwardInfo, 'from_chat_id');
            $senderName = data_get($forwardInfo, 'author_signature')
                ?? data_get($forwardInfo, 'sender_name');

            if (filled($senderUserId) || filled($senderName)) {
                $sender = [
                    'id' => $senderUserId,
                    'name' => $senderName,
                    'username' => data_get($forwardInfo, 'sender_username')
                        ?? data_get($forwardInfo, 'from_message_sender.username')
                        ?? $this->resolveTelegramPayloadUserUsername($payload, $senderUserId),
                ];
            }
        }

        if (! is_array($sender)) {
            return null;
        }

        $originalMessageId = data_get($origin, 'message_id')
            ?? data_get($forwardInfo, 'from_message_id');

        return [
            'provider' => Channel::PLATFORM_TELEGRAM,
            'sender' => $sender,
            'message' => [
                'forward_info' => $forwardInfo,
            ],
            'original_message_id' => $originalMessageId,
        ];
    }

    protected function resolveConversationForwardedSenderUserId(Message $message): ?string
    {
        $source = $this->resolveConversationForwardedSource($message);
        $sender = data_get($source, 'sender');

        return is_array($sender)
            ? $this->resolveProviderSenderUserId($sender)
            : null;
    }

    /**
     * @param  Collection<string, Collection<int, ContactIdentity>>  $forwardedIdentityIndex
     */
    protected function resolveForwardedContactIdentity(
        Message $message,
        ?string $senderUserId,
        Collection $forwardedIdentityIndex,
    ): ?ContactIdentity {
        $platform = $this->normalizeMediaBadgeText($message->channel?->platform);

        if ($platform === null || $senderUserId === null) {
            return null;
        }

        $identities = $forwardedIdentityIndex->get($this->forwardedIdentityIndexKey($platform, $senderUserId));

        if (! $identities instanceof Collection || $identities->isEmpty()) {
            return null;
        }

        /** @var ContactIdentity|null $identity */
        $identity = $identities
            ->sortByDesc(fn (ContactIdentity $identity): int => (int) ($identity->channel_id === $message->channel_id))
            ->first();

        return $identity;
    }

    /**
     * @param  Collection<string, Collection<int, ContactIdentity>>  $contactShareIdentityIndex
     */
    protected function resolveContactShareContactIdentity(
        Message $message,
        ?string $sharedContactUserId,
        Collection $contactShareIdentityIndex,
    ): ?ContactIdentity {
        $platform = $this->normalizeMediaBadgeText($message->channel?->platform);

        if ($platform === null || $sharedContactUserId === null) {
            return null;
        }

        $identities = $contactShareIdentityIndex->get($this->forwardedIdentityIndexKey($platform, $sharedContactUserId));

        if (! $identities instanceof Collection || $identities->isEmpty()) {
            return null;
        }

        /** @var ContactIdentity|null $identity */
        $identity = $identities
            ->sortByDesc(fn (ContactIdentity $identity): int => (int) ($identity->channel_id === $message->channel_id))
            ->first();

        return $identity;
    }

    protected function forwardedIdentityIndexKey(string $platform, string $externalUserId): string
    {
        return Str::lower($platform).'|'.$externalUserId;
    }

    protected function replyMessageIndexKey(int $channelId, string $externalChatId, string $externalMessageId): string
    {
        return $channelId.'|'.$externalChatId.'|'.$externalMessageId;
    }

    protected function resolveConversationReplyOriginalMessageId(Message $message): ?string
    {
        $source = $this->resolveConversationReplySource($message);

        return $source === null
            ? null
            : $this->normalizeMediaBadgeText(data_get($source, 'original_message_id'));
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveConversationReplySource(Message $message): ?array
    {
        return $this->resolveMaxConversationReplySource($message)
            ?? $this->resolveTelegramConversationReplySource($message);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveMaxConversationReplySource(Message $message): ?array
    {
        $platform = $this->normalizeMediaBadgeText($message->channel?->platform);

        if (Str::lower((string) $platform) !== Channel::PLATFORM_MAX) {
            return null;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $link = data_get($payload, 'message.link');

        if (! is_array($link)) {
            return null;
        }

        $linkType = $this->normalizeMediaBadgeText(data_get($link, 'type'));

        if (Str::lower((string) $linkType) !== 'reply') {
            return null;
        }

        $linkedMessage = data_get($link, 'message');
        $originalMessageId = data_get($linkedMessage, 'mid')
            ?? data_get($link, 'mid')
            ?? data_get($link, 'message_id');

        return [
            'provider' => Channel::PLATFORM_MAX,
            'message' => is_array($linkedMessage) ? $linkedMessage : null,
            'original_message_id' => $originalMessageId,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function resolveTelegramConversationReplySource(Message $message): ?array
    {
        $platform = $this->normalizeMediaBadgeText($message->channel?->platform);

        if (Str::lower((string) $platform) !== Channel::PLATFORM_TELEGRAM) {
            return null;
        }

        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $telegramMessage = data_get($payload, 'message');
        $linkedMessage = is_array(data_get($telegramMessage, 'reply_to_message'))
            ? data_get($telegramMessage, 'reply_to_message')
            : null;
        $tdlibReplyTo = is_array(data_get($payload, 'reply_to'))
            ? data_get($payload, 'reply_to')
            : (is_array(data_get($telegramMessage, 'reply_to')) ? data_get($telegramMessage, 'reply_to') : null);

        if ($linkedMessage === null && is_array($tdlibReplyTo)) {
            $linkedMessage = $tdlibReplyTo;
        }

        $originalMessageId = $this->normalizeMediaBadgeText(
            data_get($telegramMessage, 'reply_to_message.message_id')
                ?? data_get($payload, '_gateway_event.reply_to_message_id')
                ?? data_get($payload, 'reply_to_message_id')
                ?? data_get($payload, 'reply_to.message_id')
                ?? data_get($payload, 'reply_to.external_message_id')
                ?? data_get($payload, 'reply_to.message.message_id')
                ?? data_get($payload, 'reply_to.message.external_message_id')
                ?? data_get($telegramMessage, 'reply_to.message_id')
                ?? data_get($telegramMessage, 'reply_to.external_message_id')
                ?? data_get($telegramMessage, 'reply_to.message.message_id')
                ?? data_get($telegramMessage, 'reply_to.message.external_message_id')
        );

        if ($originalMessageId === null) {
            return null;
        }

        return [
            'provider' => Channel::PLATFORM_TELEGRAM,
            'message' => is_array($linkedMessage) ? $linkedMessage : null,
            'original_message_id' => $originalMessageId,
        ];
    }

    /**
     * @param  array<string, mixed>  $linkedMessage
     */
    protected function resolveConversationReplyPreviewText(array $linkedMessage): ?string
    {
        return $this->normalizeMediaBadgeText(
            data_get($linkedMessage, 'text')
                ?? data_get($linkedMessage, 'body.text')
                ?? data_get($linkedMessage, 'caption')
                ?? data_get($linkedMessage, 'body.caption')
                ?? data_get($linkedMessage, 'content.text.text')
                ?? data_get($linkedMessage, 'content.caption.text')
                ?? data_get($linkedMessage, 'formatted_text.text')
                ?? data_get($linkedMessage, 'quote.text.text')
                ?? data_get($linkedMessage, 'quote.text')
        );
    }

    /**
     * @param  array<string, mixed>  $sender
     */
    protected function resolveProviderSenderName(array $sender): ?string
    {
        $name = $this->normalizeMediaBadgeText(data_get($sender, 'name'))
            ?? $this->normalizeMediaBadgeText(data_get($sender, 'title'));

        if ($name !== null) {
            return Str::limit(Str::squish($name), 80);
        }

        $fullName = collect([
            $this->normalizeMediaBadgeText(data_get($sender, 'first_name')),
            $this->normalizeMediaBadgeText(data_get($sender, 'last_name')),
        ])
            ->filter()
            ->map(static fn (string $part): string => Str::squish($part))
            ->implode(' ');

        if ($fullName !== '') {
            return Str::limit($fullName, 80);
        }

        $username = $this->normalizeMediaBadgeText(data_get($sender, 'username'));

        if ($username !== null) {
            return Str::limit('@'.ltrim(Str::squish($username), '@'), 80);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $sender
     */
    protected function resolveProviderSenderUserId(array $sender): ?string
    {
        return $this->normalizeMediaBadgeText(data_get($sender, 'user_id'))
            ?? $this->normalizeMediaBadgeText(data_get($sender, 'id'));
    }

    /**
     * @param  array<string, mixed>  $sender
     */
    protected function resolveProviderSenderUsername(array $sender): ?string
    {
        foreach ([
            data_get($sender, 'username'),
            data_get($sender, 'user_name'),
            data_get($sender, 'sender_username'),
            data_get($sender, 'public_username'),
            data_get($sender, 'usernames.active_usernames.0'),
            data_get($sender, 'usernames.editable_username'),
            data_get($sender, 'usernames.0'),
        ] as $username) {
            $normalized = $this->normalizeMediaBadgeText($username);

            if ($normalized !== null) {
                return Str::limit('@'.ltrim(Str::squish($normalized), '@'), 80);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveTelegramPayloadUserUsername(array $payload, mixed $userId): ?string
    {
        $normalizedUserId = $this->normalizeMediaBadgeText($userId);
        $users = data_get($payload, 'users');

        if ($normalizedUserId === null || ! is_array($users)) {
            return null;
        }

        foreach ($users as $key => $user) {
            if (! is_array($user)) {
                continue;
            }

            $candidateUserId = $this->normalizeMediaBadgeText(data_get($user, 'id'))
                ?? $this->normalizeMediaBadgeText(data_get($user, 'user_id'))
                ?? (is_string($key) || is_int($key) ? $this->normalizeMediaBadgeText($key) : null);

            if ($candidateUserId !== $normalizedUserId) {
                continue;
            }

            return $this->resolveProviderSenderUsername($user);
        }

        return null;
    }

    protected function formatForwardedContactLabel(?ContactIdentity $identity): string
    {
        if ($identity === null) {
            return 'не найден';
        }

        $contactName = $this->normalizeMediaBadgeText($identity->contact?->name)
            ?? collect([
                $this->normalizeMediaBadgeText($identity->contact?->first_name),
                $this->normalizeMediaBadgeText($identity->contact?->last_name),
            ])
                ->filter()
                ->map(static fn (string $part): string => Str::squish($part))
                ->implode(' ')
            ?: $this->normalizeMediaBadgeText($identity->display_name);

        $label = '#'.$identity->contact_id;

        if ($contactName !== null) {
            $label .= ' '.Str::limit(Str::squish($contactName), 80);
        }

        return $label;
    }

    protected function resolveForwardedContactUrl(?ContactIdentity $identity): ?string
    {
        if ($identity?->contact_id === null) {
            return null;
        }

        return ContactResource::getUrl('view', ['record' => $identity->contact_id]);
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

        $forwardedDisplayText = $this->resolveForwardedConversationDisplayText($message);

        if ($forwardedDisplayText !== null) {
            return $forwardedDisplayText;
        }

        return match ($message->message_kind) {
            Message::KIND_INBOUND_CONTACT_SHARE => 'Поделился контактом',
            Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION => 'Спасибо, номер получили.',
            Message::KIND_OUTBOUND_AUTO_REPLY => 'Автоответ',
            Message::KIND_OUTBOUND_MANUAL_REPLY => 'Ответ оператора',
            Message::KIND_OUTBOUND_EXTERNAL_ACCOUNT_MESSAGE => 'Исходящее из Telegram',
            Message::KIND_OUTBOUND_DATA_COLLECTION_QUESTION => 'Вопрос сбора данных',
            Message::KIND_OUTBOUND_DATA_COLLECTION_COMPLETION => 'Спасибо, данные сохранили.',
            default => 'Системное сообщение',
        };
    }

    protected function resolveForwardedConversationDisplayText(Message $message): ?string
    {
        $source = $this->resolveConversationForwardedSource($message);

        if ($source === null) {
            return null;
        }

        $forwardedText = $this->normalizeMediaBadgeText(
            data_get($source, 'message.text')
                ?? data_get($source, 'message.body.text')
                ?? data_get($source, 'message.caption')
        );

        return $forwardedText ?? 'Пересланное сообщение без доступного содержимого';
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
        foreach ($this->resolveSharedContactCandidateContainers($message) as $container) {
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

    protected function resolveSharedContactName(Message $message): ?string
    {
        foreach ($this->resolveSharedContactCandidateContainers($message) as $container) {
            $name = $this->normalizeMediaBadgeText(
                data_get($container, 'name')
                ?? data_get($container, 'contact.name')
                ?? data_get($container, 'payload.contact.name')
                ?? data_get($container, 'max_info.name')
                ?? data_get($container, 'payload.max_info.name')
            );

            if ($name !== null) {
                return Str::limit(Str::squish($name), 80);
            }

            $fullName = collect([
                $this->normalizeMediaBadgeText(data_get($container, 'first_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'last_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'contact.first_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'contact.last_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'payload.contact.first_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'payload.contact.last_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'max_info.first_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'max_info.last_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'payload.max_info.first_name')),
                $this->normalizeMediaBadgeText(data_get($container, 'payload.max_info.last_name')),
            ])
                ->filter()
                ->map(static fn (string $part): string => Str::squish($part))
                ->unique()
                ->implode(' ');

            if ($fullName !== '') {
                return Str::limit($fullName, 80);
            }
        }

        return null;
    }

    protected function resolveSharedContactUserId(Message $message): ?string
    {
        foreach ($this->resolveSharedContactCandidateContainers($message) as $container) {
            $userId = $this->normalizeMediaBadgeText(
                data_get($container, 'user_id')
                ?? data_get($container, 'contact.user_id')
                ?? data_get($container, 'payload.contact.user_id')
                ?? data_get($container, 'max_info.user_id')
                ?? data_get($container, 'payload.max_info.user_id')
            );

            if ($userId !== null) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function resolveSharedContactCandidateContainers(Message $message): array
    {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $candidateContainers = [];

        foreach ([
            data_get($payload, 'message.contact'),
            data_get($payload, 'message.body.contact'),
            data_get($payload, 'message.body'),
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
            data_get($payload, 'message.attachments'),
            data_get($payload, 'message.body.attachments'),
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
                    data_get($attachment, 'max_info'),
                    data_get($attachment, 'payload.max_info'),
                ] as $container) {
                    if (is_array($container)) {
                        $candidateContainers[] = $container;
                    }
                }
            }
        }

        return $candidateContainers;
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
            if (! is_array($item)) {
                continue;
            }

            $status = $this->resolveConversationMediaItemStatus($message, $item);
            $displayStatus = $this->resolveConversationMediaDisplayStatus($item, $status);

            if ($displayStatus === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                continue;
            }

            $statusCounts[$displayStatus] = ($statusCounts[$displayStatus] ?? 0) + 1;
        }

        if ($statusCounts === []) {
            return [];
        }

        $badges = [];

        foreach ([
            'unsupported',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL,
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING,
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
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
            'image',
            'photo' => 'Фото',
            'video' => 'Видео',
            'video_note' => 'Кружок',
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
        return $this->resolveMessageMediaItemsAction->handle($message);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @return list<array<string, mixed>>
     */
    protected function resolveConversationGroupMediaItems(Collection $messages): array
    {
        $items = [];

        foreach ($messages as $message) {
            foreach ($this->resolveConversationMediaItems($message) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $items[] = [
                    ...$item,
                    'message_id' => $message->id,
                ];
            }
        }

        return $items;
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  list<array<string, mixed>>  $mediaItems
     */
    protected function resolveConversationGroupDisplayText(Message $leader, Collection $messages, array $mediaItems): string
    {
        if ($messages->count() <= 1) {
            return $this->resolveConversationDisplayText($leader, $mediaItems);
        }

        $caption = $messages
            ->map(fn (Message $message): ?string => $this->normalizeMediaBadgeText($message->text))
            ->filter()
            ->first();

        if ($caption !== null) {
            return $caption;
        }

        return $this->resolveConversationDisplayText($leader, $mediaItems);
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function resolveConversationGroupFormattedHtml(Collection $messages): ?string
    {
        foreach ($messages as $message) {
            $formattedHtml = $this->resolveConversationFormattedHtml($message);

            if ($formattedHtml !== null) {
                return $formattedHtml;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function resolveConversationGroupHtmlSourceText(Collection $messages): ?string
    {
        foreach ($messages as $message) {
            $sourceText = $this->resolveConversationHtmlSourceText($message);

            if ($sourceText !== null) {
                return $sourceText;
            }
        }

        return null;
    }

    protected function resolveConversationMediaDownloadStatus(Message $message, array $item): ?string
    {
        $status = $this->normalizeMediaBadgeText(data_get($item, 'download_status'));

        return MessageAttachment::normalizeDownloadStatus($status)
            ?? MessageAttachment::downloadStatusFromLegacyStatus($status);
    }

    protected function formatConversationMediaStateLabel(string $status): string
    {
        return match ($status) {
            'unsupported' => 'Не поддерживается',
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY => 'Только метаданные',
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD => 'Ожидает загрузки',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING => 'Загружается',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED => 'Готово',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED => 'Ошибка загрузки',
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL => 'Файл удалён',
            default => 'Статус не определён',
        };
    }

    protected function formatConversationMediaStateTone(string $status): string
    {
        return match ($status) {
            'unsupported' => 'warning',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED => 'success',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED => 'danger',
            MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING => 'warning',
            MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD => 'gray',
            MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY,
            MessageAttachment::DOWNLOAD_STATUS_DELETED_LOCAL => 'gray',
            default => 'gray',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    protected function resolveConversationMediaItemViewData(Message $message, array $media): array
    {
        $items = [];

        foreach ($media as $item) {
            if (! is_array($item)) {
                continue;
            }

            $status = $this->resolveConversationMediaItemStatus($message, $item);
            $mediaKind = (string) data_get($item, 'media_kind', data_get($item, 'type', ''));
            $mediaKindLabel = $this->formatMediaTypeLabel($mediaKind);
            $fileName = $this->normalizeMediaBadgeText(data_get($item, 'file_name'));
            $title = MessageAttachment::normalizeMediaKind($mediaKind) === MessageAttachment::MEDIA_KIND_STICKER
                ? $mediaKindLabel
                : ($fileName ?? $mediaKindLabel);
            $mimeType = $this->normalizeMediaBadgeText(data_get($item, 'mime_type'));
            $fileSizeLabel = $this->formatMediaFileSizeLabel(data_get($item, 'file_size_bytes'));
            $durationLabel = $this->formatMediaDurationLabel(data_get($item, 'duration'));
            $attachmentId = $this->normalizeMediaAttachmentId(data_get($item, 'attachment_id'));
            $isVideoNote = MessageAttachment::normalizeMediaKind($mediaKind) === MessageAttachment::MEDIA_KIND_VIDEO_NOTE
                || data_get($item, 'is_video_note') === true;
            $isDownloadable = (bool) data_get($item, 'is_locally_downloadable', false)
                && $status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                && $attachmentId !== null;
            $previewKind = $this->normalizeConversationMediaPreviewKind(data_get($item, 'preview_kind'));
            $isPreviewable = (bool) data_get($item, 'is_inline_previewable', false)
                && $status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                && $attachmentId !== null
                && $previewKind !== null;
            $displayStatus = $this->resolveConversationMediaDisplayStatus($item, $status);

            $items[] = [
                'source' => (string) data_get($item, 'source', 'unknown'),
                'attachment_id' => $attachmentId,
                'media_kind' => $mediaKind,
                'media_kind_label' => $mediaKindLabel,
                'title' => $title,
                'file_name' => $fileName,
                'mime_type' => $mimeType,
                'file_size_label' => $fileSizeLabel,
                'duration_label' => $durationLabel,
                'is_video_note' => $isVideoNote,
                'status' => $status,
                'status_label' => $this->formatConversationMediaStateLabel($displayStatus),
                'status_tone' => $this->formatConversationMediaStateTone($displayStatus),
                'show_status' => $this->shouldShowConversationMediaStatus($displayStatus),
                'error_message' => $this->normalizeMediaBadgeText(data_get($item, 'safe_error_message')),
                'is_downloadable' => $isDownloadable,
                'is_previewable' => $isPreviewable,
                'preview_kind' => $isPreviewable ? $previewKind : null,
                'preview_url' => $isPreviewable
                    ? route('admin.message-attachments.preview', ['attachment' => $attachmentId])
                    : null,
                'poster_url' => $this->shouldExposeConversationVideoPosterUrl($item, $isPreviewable, $previewKind, $isVideoNote, $attachmentId)
                    ? route('admin.message-attachments.poster', ['attachment' => $attachmentId])
                    : null,
                'download_url' => $isDownloadable
                    ? route('admin.message-attachments.download', ['attachment' => $attachmentId])
                    : null,
                'meta' => array_values(array_filter([
                    $mimeType,
                    $durationLabel,
                    $fileSizeLabel,
                ], static fn (?string $value): bool => $value !== null)),
            ];
        }

        return $items;
    }

    protected function shouldShowConversationMediaStatus(string $displayStatus): bool
    {
        return $displayStatus !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    protected function shouldExposeConversationVideoPosterUrl(
        array $item,
        bool $isPreviewable,
        ?string $previewKind,
        bool $isVideoNote,
        ?int $attachmentId,
    ): bool {
        return $isPreviewable
            && $previewKind === MessageAttachment::PREVIEW_KIND_VIDEO
            && ! $isVideoNote
            && $attachmentId !== null
            && data_get($item, 'provider') === MessageAttachment::PROVIDER_MAX_BOT;
    }

    protected function normalizeConversationMediaPreviewKind(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (trim($value)) {
            MessageAttachment::PREVIEW_KIND_IMAGE,
            MessageAttachment::PREVIEW_KIND_VIDEO,
            MessageAttachment::PREVIEW_KIND_AUDIO => trim($value),
            default => null,
        };
    }

    protected function resolveConversationMediaItemStatus(Message $message, array $item): string
    {
        return $this->resolveConversationMediaDownloadStatus($message, $item)
            ?? MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY;
    }

    protected function resolveConversationMediaDisplayStatus(array $item, string $status): string
    {
        $errorCode = $this->normalizeMediaBadgeText(data_get($item, 'safe_error_code'));
        $mediaKind = (string) data_get($item, 'media_kind', data_get($item, 'type', ''));

        if ($errorCode === 'unsupported_media_kind' || MessageAttachment::normalizeMediaKind($mediaKind) === MessageAttachment::MEDIA_KIND_UNKNOWN) {
            return 'unsupported';
        }

        return $status;
    }

    protected function normalizeMediaAttachmentId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    protected function normalizeConversationProviderGroupKey(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' && $normalized !== '0' ? $normalized : null;
    }

    protected function formatMediaDurationLabel(mixed $value): ?string
    {
        $seconds = null;

        if (is_int($value) && $value >= 0) {
            $seconds = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $seconds = (int) $value;
        }

        if ($seconds === null) {
            return null;
        }

        $minutes = intdiv($seconds, 60);
        $rest = str_pad((string) ($seconds % 60), 2, '0', STR_PAD_LEFT);

        return $minutes.':'.$rest;
    }

    protected function formatMediaFileSizeLabel(mixed $value): ?string
    {
        $bytes = null;

        if (is_int($value) && $value >= 0) {
            $bytes = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $bytes = (int) $value;
        }

        if ($bytes === null) {
            return null;
        }

        if ($bytes < 1024) {
            return $bytes.' Б';
        }

        if ($bytes < 1024 * 1024) {
            return $this->formatMediaFileSizeNumber($bytes / 1024).' КБ';
        }

        return $this->formatMediaFileSizeNumber($bytes / (1024 * 1024)).' МБ';
    }

    protected function formatMediaFileSizeNumber(float $value): string
    {
        $formatted = number_format($value, $value >= 10 ? 0 : 1, ',', ' ');

        return rtrim(rtrim($formatted, '0'), ',');
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
        $richTextHtml = $this->abRichTextHtmlRenderer->render($message->rich_text);

        if ($richTextHtml !== null) {
            return $richTextHtml;
        }

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
