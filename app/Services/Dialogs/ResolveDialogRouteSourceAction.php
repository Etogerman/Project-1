<?php

namespace App\Services\Dialogs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Support\Collection;

class ResolveDialogRouteSourceAction
{
    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly ResolveOrCreateDialogAction $resolveOrCreateDialogAction,
    ) {}

    public function forContact(Contact $contact): ?Dialog
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);

        /** @var Collection<int, Dialog> $dialogs */
        $dialogs = Dialog::query()
            ->with(['channel', 'currentContactIdentity'])
            ->where('contact_id', $rootContact->id)
            ->get();

        return $dialogs
            ->filter(fn (Dialog $dialog): bool => $this->canBeUsedAsRouteSource($dialog))
            ->sortByDesc(fn (Dialog $dialog): string => $this->dialogSortKey($dialog))
            ->first();
    }

    public function forMessage(Message $message): ?Dialog
    {
        $message->loadMissing([
            'dialog.channel',
            'dialog.currentContactIdentity',
            'channel',
            'contact',
        ]);

        if ($message->dialog instanceof Dialog && $this->canBeUsedAsRouteSource($message->dialog)) {
            return $message->dialog;
        }

        if (! filled($message->channel_id) || ! $message->contact instanceof Contact) {
            return null;
        }

        $rootContact = $this->resolveRootContactAction->handle($message->contact);

        $dialog = Dialog::query()
            ->with(['channel', 'currentContactIdentity'])
            ->where('contact_id', $rootContact->id)
            ->where('channel_id', $message->channel_id)
            ->first();

        if (! $dialog instanceof Dialog) {
            return null;
        }

        return $this->canBeUsedAsRouteSource($dialog) ? $dialog : null;
    }

    public function fallbackFromLegacyMessage(Message $message): ?Dialog
    {
        $message->loadMissing(['channel', 'contact', 'contactIdentity']);

        if (! $this->legacyMessageCanBeUsedAsRouteSource($message) || ! $message->channel instanceof Channel || ! $message->contact instanceof Contact) {
            return null;
        }

        $dialog = $this->resolveOrCreateDialogAction->handle($message->contact, $message->channel);
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $payload = $this->resolveLegacyRouteSourcePayload($dialog, $message);

        if ($payload !== []) {
            $dialog->forceFill($payload)->save();
            $dialog->refresh();
            $dialog->loadMissing(['channel', 'currentContactIdentity']);
        }

        return $this->canBeUsedAsRouteSource($dialog) ? $dialog : null;
    }

    public function canBeUsedAsRouteSource(Dialog $dialog): bool
    {
        $dialog->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $dialog->channel;

        if (! $channel instanceof Channel || ! $channel->is_active || $channel->connection_type !== Channel::CONNECTION_TYPE_BOT || ! filled($channel->getToken())) {
            return false;
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($dialog->external_chat_id),
            Channel::PLATFORM_MAX => filled($dialog->external_chat_id) || filled($dialog->currentContactIdentity?->external_user_id),
            default => false,
        };
    }

    public function legacyMessageCanBeUsedAsRouteSource(Message $message): bool
    {
        $message->loadMissing(['channel', 'contactIdentity']);

        $channel = $message->channel;

        if (! $channel instanceof Channel || ! $channel->is_active || $channel->connection_type !== Channel::CONNECTION_TYPE_BOT || ! filled($channel->getToken())) {
            return false;
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($message->external_chat_id),
            Channel::PLATFORM_MAX => filled($message->external_chat_id) || filled($message->contactIdentity?->external_user_id),
            default => false,
        };
    }

    private function dialogSortKey(Dialog $dialog): string
    {
        return sprintf(
            '%010d-%010d-%010d',
            $dialog->last_inbound_at?->getTimestamp() ?? 0,
            $dialog->last_message_at?->getTimestamp() ?? 0,
            $dialog->id,
        );
    }

    private function shouldRefreshLegacyIdentity(Dialog $dialog, ContactIdentity $legacyIdentity): bool
    {
        if ($dialog->current_contact_identity_id === null) {
            return true;
        }

        return ! filled($dialog->currentContactIdentity?->external_user_id) && filled($legacyIdentity->external_user_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveLegacyRouteSourcePayload(Dialog $dialog, Message $message): array
    {
        $legacyIdentity = $message->contactIdentity;
        $channel = $message->channel;

        if (! $legacyIdentity instanceof ContactIdentity || ! $channel instanceof Channel) {
            return [];
        }

        return match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => filled($message->external_chat_id)
                ? [
                    'current_contact_identity_id' => $legacyIdentity->id,
                    'external_chat_id' => $message->external_chat_id,
                ]
                : [],
            Channel::PLATFORM_MAX => $this->resolveMaxLegacyRouteSourcePayload($dialog, $legacyIdentity, $message),
            default => filled($message->external_chat_id)
                ? [
                    'current_contact_identity_id' => $legacyIdentity->id,
                    'external_chat_id' => $message->external_chat_id,
                ]
                : [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveMaxLegacyRouteSourcePayload(
        Dialog $dialog,
        ContactIdentity $legacyIdentity,
        Message $message,
    ): array {
        if (filled($message->external_chat_id)) {
            return [
                'current_contact_identity_id' => $legacyIdentity->id,
                'external_chat_id' => $message->external_chat_id,
            ];
        }

        if ($this->shouldRefreshLegacyIdentity($dialog, $legacyIdentity)) {
            return [
                'current_contact_identity_id' => $legacyIdentity->id,
                'external_chat_id' => null,
            ];
        }

        return [];
    }
}
