<?php

namespace App\Jobs;

use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\IsDialogReadyForBitrix24LiveBridgeAction;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\ResolveAutoReplyRuleAction;
use App\Services\Contacts\ResolveRootContactAction;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use App\Services\Dialogs\CanSendThroughDialogAction;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessDeferredParameterAutoReplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly int $dialogId,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30, 60];
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("deferred-parameter-auto-reply:dialog:{$this->dialogId}"))
                ->releaseAfter(10)
                ->expireAfter(180),
        ];
    }

    public function handle(
        ResolveRootContactAction $resolveRootContactAction,
        ResolveNextDataCollectionFieldAction $resolveNextDataCollectionFieldAction,
        IsDialogReadyForBitrix24LiveBridgeAction $isDialogReadyForBitrix24LiveBridgeAction,
        CanSendThroughDialogAction $canSendThroughDialogAction,
        ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction,
        BotAutoReplyService $botAutoReplyService,
    ): void {
        $dialog = Dialog::query()
            ->with(['channel', 'contact', 'currentContactIdentity'])
            ->find($this->dialogId);

        if (! $dialog instanceof Dialog) {
            return;
        }

        $sourceMessageId = $dialog->pending_auto_reply_source_message_id;

        if (! filled($sourceMessageId)) {
            return;
        }

        $sourceMessage = Message::query()
            ->with(['channel', 'contactIdentity', 'contact', 'dialog'])
            ->find($sourceMessageId);

        if (! $sourceMessage instanceof Message) {
            $this->clearPendingIfCurrent($dialog->id, (int) $sourceMessageId);

            return;
        }

        if (! filled($sourceMessage->message_parameter)) {
            $this->clearPendingIfCurrent($dialog->id, $sourceMessage->id);

            return;
        }

        if ($sourceMessage->auto_reply_sent_at !== null) {
            $this->clearPendingIfCurrent($dialog->id, $sourceMessage->id);

            return;
        }

        $dialogContact = $dialog->contact;

        if ($dialogContact === null) {
            return;
        }

        $rootContact = $resolveRootContactAction->handle($dialogContact);

        if (! $rootContact->isAutoReplyEnabled()) {
            return;
        }

        if (! $rootContact->phoneNumbers()
            ->whereNotNull('phone_normalized')
            ->where('phone_normalized', '!=', '')
            ->exists()) {
            return;
        }

        if ($rootContact->isInDataCollection()) {
            return;
        }

        if ($resolveNextDataCollectionFieldAction->handle($rootContact) !== null) {
            return;
        }

        if (! $isDialogReadyForBitrix24LiveBridgeAction->handle($dialog)) {
            return;
        }

        if (! $canSendThroughDialogAction->handle($dialog)) {
            return;
        }

        $channel = $dialog->channel;

        if ($channel === null) {
            return;
        }

        $matchedRule = $resolveAutoReplyRuleAction->resolveDelayedFinalRule(
            $channel,
            $rootContact,
            $sourceMessage->message_parameter,
        );

        if ($matchedRule === null) {
            $this->clearPendingIfCurrent($dialog->id, $sourceMessage->id);

            return;
        }

        $botAutoReplyService->handleResolvedRule($sourceMessage, $matchedRule, $dialog);

        $this->clearPendingIfCurrent($dialog->id, $sourceMessage->id);
    }

    private function clearPendingIfCurrent(int $dialogId, int $sourceMessageId): void
    {
        Dialog::query()
            ->whereKey($dialogId)
            ->where('pending_auto_reply_source_message_id', $sourceMessageId)
            ->update([
                'pending_auto_reply_source_message_id' => null,
                'updated_at' => now(),
            ]);
    }
}
