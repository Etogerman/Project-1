<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;

class ResolveDialogRoutePayloadAction
{
    /**
     * @return array<string, mixed>
     */
    public function forInboundMessage(Channel $channel, Message $message): array
    {
        $message->loadMissing('contactIdentity');

        return $this->buildPayload(
            $channel,
            $message->contact_identity_id,
            $message->contactIdentity?->external_user_id,
            $message->external_chat_id,
            false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forIdentityFallback(ContactIdentity $identity): array
    {
        return [
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forDialogFallback(Channel $channel, Dialog $dialog): array
    {
        $dialog->loadMissing('currentContactIdentity');

        return $this->buildPayload(
            $channel,
            $dialog->current_contact_identity_id,
            $dialog->currentContactIdentity?->external_user_id,
            $dialog->external_chat_id,
            true,
        );
    }

    public function messageCanProvideRouteSource(Message $message, Channel $channel): bool
    {
        $message->loadMissing('contactIdentity');

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($message->contact_identity_id) && filled($message->external_chat_id),
            Channel::PLATFORM_MAX => filled($message->contact_identity_id)
                && (filled($message->external_chat_id) || filled($message->contactIdentity?->external_user_id)),
            default => filled($message->contact_identity_id) && filled($message->external_chat_id),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(
        Channel $channel,
        mixed $contactIdentityId,
        ?string $externalUserId,
        ?string $externalChatId,
        bool $allowIdentityOnly,
    ): array {
        if (! filled($contactIdentityId) && ! filled($externalChatId)) {
            return [];
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->buildTelegramPayload($contactIdentityId, $externalChatId, $allowIdentityOnly),
            Channel::PLATFORM_MAX => $this->buildMaxPayload($contactIdentityId, $externalUserId, $externalChatId, $allowIdentityOnly),
            default => $this->buildDefaultPayload($contactIdentityId, $externalChatId, $allowIdentityOnly),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTelegramPayload(mixed $contactIdentityId, ?string $externalChatId, bool $allowIdentityOnly): array
    {
        if (filled($externalChatId)) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => $externalChatId,
            ];
        }

        if ($allowIdentityOnly && filled($contactIdentityId)) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => null,
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildMaxPayload(
        mixed $contactIdentityId,
        ?string $externalUserId,
        ?string $externalChatId,
        bool $allowIdentityOnly,
    ): array {
        if (filled($externalChatId)) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => $externalChatId,
            ];
        }

        if ((filled($externalUserId) && filled($contactIdentityId)) || ($allowIdentityOnly && filled($contactIdentityId))) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => null,
            ];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDefaultPayload(mixed $contactIdentityId, ?string $externalChatId, bool $allowIdentityOnly): array
    {
        if (filled($externalChatId)) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => $externalChatId,
            ];
        }

        if ($allowIdentityOnly && filled($contactIdentityId)) {
            return [
                'current_contact_identity_id' => $contactIdentityId,
                'external_chat_id' => null,
            ];
        }

        return [];
    }
}
