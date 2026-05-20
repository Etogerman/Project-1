<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use DateTimeInterface;

class SyncMessageDialogMetadataAction
{
    public function __construct(
        private readonly ResolveOrCreateDialogAction $resolveOrCreateDialogAction,
        private readonly MessageChronology $messageChronology,
        private readonly BuildDialogMessageSnapshotPayloadAction $buildDialogMessageSnapshotPayloadAction,
    ) {}

    public function handle(
        Message $message,
        Contact|int $contact,
        Channel|int $channel,
        ?ContactIdentity $contactIdentity,
        ?string $externalChatId,
        string $sentByType,
        ?int $sentByUserId = null,
        ?string $sentBySystemCode = null,
    ): Message {
        $dialog = $this->resolveOrCreateDialogAction->handle($contact, $channel);
        $dialog = $this->lockDialog($dialog);
        $messageSortAt = $this->messageChronology->resolveSortAt($message);

        $this->touchDialog(
            $dialog,
            $message,
            $messageSortAt,
            $contactIdentity,
            $externalChatId,
        );

        $payload = [
            'dialog_id' => $dialog->id,
            'sent_by_type' => $sentByType,
            'sent_by_user_id' => $sentByUserId,
            'sent_by_system_code' => $sentBySystemCode,
        ];

        if ($this->messageNeedsUpdate($message, $payload)) {
            $message->forceFill($payload)->save();
        }

        return $message;
    }

    private function touchDialog(
        Dialog $dialog,
        Message $message,
        mixed $messageAt,
        ?ContactIdentity $contactIdentity,
        ?string $externalChatId,
    ): void {
        $payload = [
            'last_message_at' => $this->maxDateTimeValue($dialog->last_message_at, $messageAt),
        ];

        $payload = array_merge($payload, $this->resolveMessageSnapshotPayload($dialog, $message, $messageAt));

        if ($message->direction === Message::DIRECTION_INBOUND) {
            $payload['last_inbound_at'] = $this->maxDateTimeValue($dialog->last_inbound_at, $messageAt);

            if ($this->shouldRefreshInboundRouteSource($dialog, $messageAt)) {
                $payload = array_merge(
                    $payload,
                    $this->resolveInboundRouteSourcePayload($dialog, $contactIdentity, $externalChatId),
                );
            }
        } elseif ($message->direction === Message::DIRECTION_OUTBOUND) {
            $payload['last_outbound_at'] = $this->maxDateTimeValue($dialog->last_outbound_at, $messageAt);

            if ($dialog->current_contact_identity_id === null && $contactIdentity instanceof ContactIdentity) {
                $payload['current_contact_identity_id'] = $contactIdentity->id;
            }

            if (! filled($dialog->external_chat_id) && filled($externalChatId)) {
                $payload['external_chat_id'] = $externalChatId;
            }
        }

        if (! $this->dialogNeedsUpdate($dialog, $payload)) {
            return;
        }

        $dialog->forceFill($payload)->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMessageSnapshotPayload(Dialog $dialog, Message $message, mixed $messageAt): array
    {
        $payload = [];

        if (
            $this->buildDialogMessageSnapshotPayloadAction->isVisibleDialogMessage($message)
            && $this->shouldRefreshMessageSnapshot($dialog, 'last_message_id', $message, $messageAt)
        ) {
            $payload['last_message_id'] = $message->id;
            $payload['last_message_preview'] = $this->buildDialogMessageSnapshotPayloadAction->previewText($message);
        }

        if (
            $this->buildDialogMessageSnapshotPayloadAction->isInboundMessage($message)
            && $this->shouldRefreshMessageSnapshot($dialog, 'last_inbound_message_id', $message, $messageAt)
        ) {
            $payload['last_inbound_message_id'] = $message->id;
            $payload['last_inbound_message_preview'] = $this->buildDialogMessageSnapshotPayloadAction->previewText($message);
        }

        if (
            $this->buildDialogMessageSnapshotPayloadAction->isOutboundClientMessage($message)
            && $this->shouldRefreshMessageSnapshot($dialog, 'last_outbound_message_id', $message, $messageAt)
        ) {
            $payload['last_outbound_message_id'] = $message->id;
            $payload['last_outbound_message_preview'] = $this->buildDialogMessageSnapshotPayloadAction->previewText($message);
        }

        return $payload;
    }

    private function shouldRefreshMessageSnapshot(
        Dialog $dialog,
        string $messageIdColumn,
        Message $candidateMessage,
        mixed $candidateSortAt,
    ): bool {
        $currentMessageId = $dialog->getAttribute($messageIdColumn);

        if (! filled($currentMessageId)) {
            return true;
        }

        if ((int) $currentMessageId === $candidateMessage->id) {
            return true;
        }

        $currentMessage = Message::query()
            ->select(['id', 'received_at', 'created_at'])
            ->find((int) $currentMessageId);

        if (! $currentMessage instanceof Message) {
            return true;
        }

        return $this->messageChronology->compareSortTuple(
            $candidateSortAt,
            $candidateMessage->id,
            $this->messageChronology->resolveSortAt($currentMessage),
            $currentMessage->id,
        ) >= 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function dialogNeedsUpdate(Dialog $dialog, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($this->normalizeComparableValue($dialog->getAttribute($key)) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function messageNeedsUpdate(Message $message, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($message->getAttribute($key) !== $value) {
                return true;
            }
        }

        return false;
    }

    private function maxDateTimeValue(mixed $currentValue, mixed $candidateValue): mixed
    {
        if ($currentValue === null) {
            return $candidateValue;
        }

        if ($candidateValue === null) {
            return $currentValue;
        }

        $currentTimestamp = strtotime($this->normalizeComparableValue($currentValue));
        $candidateTimestamp = strtotime($this->normalizeComparableValue($candidateValue));

        return $candidateTimestamp > $currentTimestamp ? $candidateValue : $currentValue;
    }

    private function shouldRefreshInboundRouteSource(Dialog $dialog, mixed $messageAt): bool
    {
        if ($dialog->last_inbound_at === null) {
            return true;
        }

        if ($messageAt === null) {
            return false;
        }

        $currentTimestamp = strtotime($this->normalizeComparableValue($dialog->last_inbound_at));
        $candidateTimestamp = strtotime($this->normalizeComparableValue($messageAt));

        return $candidateTimestamp >= $currentTimestamp;
    }

    private function lockDialog(Dialog $dialog): Dialog
    {
        return Dialog::query()
            ->with('channel')
            ->whereKey($dialog->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveInboundRouteSourcePayload(
        Dialog $dialog,
        ?ContactIdentity $contactIdentity,
        ?string $externalChatId,
    ): array {
        $dialog->loadMissing('channel');

        if (! $contactIdentity instanceof ContactIdentity || ! $dialog->channel instanceof Channel) {
            return [];
        }

        return match ($dialog->channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($externalChatId)
                ? [
                    'current_contact_identity_id' => $contactIdentity->id,
                    'external_chat_id' => $externalChatId,
                ]
                : [],
            Channel::PLATFORM_MAX => $this->resolveMaxRouteSourcePayload($contactIdentity, $externalChatId),
            default => filled($externalChatId)
                ? [
                    'current_contact_identity_id' => $contactIdentity->id,
                    'external_chat_id' => $externalChatId,
                ]
                : [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMaxRouteSourcePayload(ContactIdentity $contactIdentity, ?string $externalChatId): array
    {
        if (filled($externalChatId)) {
            return [
                'current_contact_identity_id' => $contactIdentity->id,
                'external_chat_id' => $externalChatId,
            ];
        }

        if (filled($contactIdentity->external_user_id)) {
            return [
                'current_contact_identity_id' => $contactIdentity->id,
                'external_chat_id' => null,
            ];
        }

        return [];
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
