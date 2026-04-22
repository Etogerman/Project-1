<?php

namespace App\Services\Dialogs;

use App\Models\Dialog;
use App\Models\Message;
use DateTimeInterface;

class SyncDialogConfirmedPhoneAction
{
    public function __construct(
        private readonly ResolveOrCreateDialogAction $resolveOrCreateDialogAction,
        private readonly ResolveDialogStageAction $resolveDialogStageAction,
        private readonly CreateDialogStageHistoryMessageAction $createDialogStageHistoryMessageAction,
    ) {}

    public function handle(Message $inboundMessage, string $phoneRaw, string $phoneNormalized): Dialog
    {
        $dialog = $this->resolveOrCreateDialogAction->handle(
            (int) $inboundMessage->contact_id,
            (int) $inboundMessage->channel_id,
        );
        $dialog = $this->lockDialog($dialog);

        $phoneRaw = trim($phoneRaw);

        if ($phoneRaw === '' || $phoneNormalized === '') {
            return $dialog;
        }

        $dialog->loadMissing('contact');
        $fromStage = $dialog->stage;

        $payload = [];

        if ($this->shouldUpdateConfirmedPhone($dialog, $inboundMessage->received_at)) {
            $payload = [
                'confirmed_phone_raw' => $phoneRaw,
                'confirmed_phone_normalized' => $phoneNormalized,
                'phone_confirmed_at' => $inboundMessage->received_at,
                'phone_confirmed_via' => Dialog::PHONE_CONFIRMED_VIA_PHONE_CAPTURE,
            ];
        }

        $payload['stage'] = $this->resolveDialogStageAction->forAttributes(
            currentStage: $dialog->stage,
            contact: $dialog->contact,
            phoneConfirmedAt: $payload['phone_confirmed_at'] ?? $dialog->phone_confirmed_at,
        );

        if (! $this->dialogNeedsUpdate($dialog, $payload)) {
            return $dialog;
        }

        $dialog->forceFill($payload)->save();

        $dialog = $dialog->fresh(['channel', 'currentContactIdentity']);

        $this->createDialogStageHistoryMessageAction->handle(
            $dialog,
            $fromStage,
            $payload['stage'],
            CreateDialogStageHistoryMessageAction::SOURCE_TYPE_SYSTEM,
        );

        return $dialog;
    }

    private function lockDialog(Dialog $dialog): Dialog
    {
        return Dialog::query()
            ->whereKey($dialog->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function shouldUpdateConfirmedPhone(Dialog $dialog, mixed $messageAt): bool
    {
        if ($dialog->phone_confirmed_at === null) {
            return true;
        }

        if ($messageAt === null) {
            return false;
        }

        $currentTimestamp = strtotime($this->normalizeComparableValue($dialog->phone_confirmed_at));
        $candidateTimestamp = strtotime($this->normalizeComparableValue($messageAt));

        return $candidateTimestamp >= $currentTimestamp;
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

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return $value;
    }
}
