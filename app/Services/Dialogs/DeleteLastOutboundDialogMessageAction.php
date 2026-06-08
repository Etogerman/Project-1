<?php

namespace App\Services\Dialogs;

use App\Data\Dialogs\DeleteLastOutboundMessageResult;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bots\TelegramBotApiService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DeleteLastOutboundDialogMessageAction
{
    public const STATUS_DELETED = 'deleted';

    public const STATUS_NOT_FOUND = 'not_found';

    public const STATUS_ALREADY_DELETED = 'already_deleted';

    public const STATUS_MISSING_LAST_MESSAGE = 'missing_last_message';

    public const STATUS_MISSING_EXTERNAL_ID = 'missing_external_id';

    public const STATUS_NOT_SUPPORTED = 'not_supported';

    public const STATUS_PROVIDER_FAILED = 'provider_failed';

    public const STATUS_INVALID_LAST_MESSAGE = 'invalid_last_message';

    public function __construct(
        private readonly TelegramBotApiService $telegramBotApiService,
    ) {}

    public function handle(Dialog $dialog): DeleteLastOutboundMessageResult
    {
        return DB::transaction(fn (): DeleteLastOutboundMessageResult => $this->handleLocked($dialog));
    }

    private function handleLocked(Dialog $dialog): DeleteLastOutboundMessageResult
    {
        $lockedDialog = Dialog::query()
            ->with(['channel'])
            ->whereKey($dialog->id)
            ->lockForUpdate()
            ->first();

        if (! $lockedDialog instanceof Dialog || ! filled($lockedDialog->last_outbound_message_id)) {
            return new DeleteLastOutboundMessageResult(self::STATUS_MISSING_LAST_MESSAGE);
        }

        $message = Message::query()
            ->whereKey((int) $lockedDialog->last_outbound_message_id)
            ->lockForUpdate()
            ->first();

        if (! $this->validLastOutboundMessage($lockedDialog, $message)) {
            $this->clearLastOutboundLink($lockedDialog);

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_INVALID_LAST_MESSAGE,
                messageId: $message instanceof Message ? (int) $message->id : null,
            );
        }

        if ($this->isLocallyDeleted($message)) {
            $this->markMessage($message, self::STATUS_ALREADY_DELETED, deleted: true);
            $this->clearLastOutboundLink($lockedDialog);

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_ALREADY_DELETED,
                messageId: (int) $message->id,
                externalMessageId: $this->externalMessageId($message),
            );
        }

        $externalMessageId = $this->externalMessageId($message);

        if ($externalMessageId === null) {
            $this->markMessage($message, self::STATUS_MISSING_EXTERNAL_ID);
            $this->clearLastOutboundLink($lockedDialog);

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_MISSING_EXTERNAL_ID,
                messageId: (int) $message->id,
            );
        }

        $channel = $lockedDialog->channel;

        if (! $this->supportsDelete($channel)) {
            $this->markMessage($message, self::STATUS_NOT_SUPPORTED);
            $this->clearLastOutboundLink($lockedDialog);

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_NOT_SUPPORTED,
                messageId: (int) $message->id,
                externalMessageId: $externalMessageId,
            );
        }

        $externalChatId = $this->externalChatId($lockedDialog, $message);

        if ($externalChatId === null) {
            $this->markMessage($message, self::STATUS_MISSING_EXTERNAL_ID);
            $this->clearLastOutboundLink($lockedDialog);

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_MISSING_EXTERNAL_ID,
                messageId: (int) $message->id,
                externalMessageId: $externalMessageId,
            );
        }

        try {
            $this->telegramBotApiService->deleteMessage($channel, $externalChatId, $externalMessageId);
        } catch (Throwable $throwable) {
            if ($this->isProviderNotFound($throwable)) {
                $this->markMessage($message, self::STATUS_NOT_FOUND, $this->safeError($throwable), deleted: true);
                $this->clearLastOutboundLink($lockedDialog);

                return new DeleteLastOutboundMessageResult(
                    status: self::STATUS_NOT_FOUND,
                    messageId: (int) $message->id,
                    externalMessageId: $externalMessageId,
                    error: $this->safeError($throwable),
                );
            }

            $this->markMessage($message, self::STATUS_PROVIDER_FAILED, $this->safeError($throwable));

            return new DeleteLastOutboundMessageResult(
                status: self::STATUS_PROVIDER_FAILED,
                messageId: (int) $message->id,
                externalMessageId: $externalMessageId,
                error: $this->safeError($throwable),
            );
        }

        $this->markMessage($message, self::STATUS_DELETED, deleted: true);
        $this->clearLastOutboundLink($lockedDialog);

        return new DeleteLastOutboundMessageResult(
            status: self::STATUS_DELETED,
            messageId: (int) $message->id,
            externalMessageId: $externalMessageId,
        );
    }

    private function validLastOutboundMessage(Dialog $dialog, mixed $message): bool
    {
        return $message instanceof Message
            && (int) $message->dialog_id === (int) $dialog->id
            && $message->direction === Message::DIRECTION_OUTBOUND;
    }

    private function supportsDelete(mixed $channel): bool
    {
        return $channel instanceof Channel
            && $channel->platform === Channel::PLATFORM_TELEGRAM
            && $channel->isBotConnection();
    }

    private function externalMessageId(Message $message): ?string
    {
        $value = trim((string) $message->external_message_id);

        return $value !== '' ? $value : null;
    }

    private function externalChatId(Dialog $dialog, Message $message): ?string
    {
        $value = trim((string) ($message->external_chat_id ?: $dialog->external_chat_id));

        return $value !== '' ? $value : null;
    }

    private function isLocallyDeleted(Message $message): bool
    {
        return filled(data_get($message->raw_payload, 'deleted_by_action_at'));
    }

    private function clearLastOutboundLink(Dialog $dialog): void
    {
        $dialog->forceFill([
            'last_outbound_message_id' => null,
            'last_outbound_message_preview' => null,
        ])->save();
    }

    private function markMessage(
        Message $message,
        string $status,
        ?string $error = null,
        bool $deleted = false,
    ): void {
        $payload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $payload['delete_action_result'] = $status;

        if ($deleted) {
            $payload['deleted_by_action_at'] = now()->toJSON();
        }

        if ($error !== null) {
            $payload['delete_action_error'] = $error;
        } else {
            unset($payload['delete_action_error']);
        }

        $message->forceFill(['raw_payload' => $payload])->save();
    }

    private function isProviderNotFound(Throwable $throwable): bool
    {
        if (! $throwable instanceof RequestException) {
            return false;
        }

        $description = Str::lower($this->safeError($throwable));

        return str_contains($description, 'message to delete not found')
            || str_contains($description, 'message not found');
    }

    private function safeError(Throwable $throwable): string
    {
        $message = $throwable->getMessage();

        if ($throwable instanceof RequestException && $throwable->response !== null) {
            $responsePayload = $throwable->response->json();
            $description = is_array($responsePayload) ? data_get($responsePayload, 'description') : null;

            if (is_string($description) && trim($description) !== '') {
                $message = $description;
            }
        }

        return Str::limit($message, 1000, '');
    }
}
