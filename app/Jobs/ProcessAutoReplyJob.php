<?php

namespace App\Jobs;

use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\ChannelActivityLog;
use App\Models\Message;
use App\Services\Bots\AutoReplyDispatchException;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\ProcessBotConstructorBlocksAction;
use App\Services\Bots\ResolveAutoReplyRuleAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAutoReplyJob implements ShouldQueue
{
    public const DEFAULT_QUEUE = 'bot-replies';

    private const RESUME_COMPLETED_EVENT = 'bot.reply_resume_completed';

    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inboundMessageId)
    {
        $this->onQueue(self::queueName());
    }

    public static function queueName(): string
    {
        $queue = trim((string) config('bots.auto_reply_queue', self::DEFAULT_QUEUE));

        return $queue !== '' ? $queue : self::DEFAULT_QUEUE;
    }

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
            (new WithoutOverlapping("auto-reply:message:{$this->inboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(
        BotAutoReplyService $botAutoReplyService,
        ChannelActivityLogger $channelActivityLogger,
        ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction,
        ProcessBotConstructorBlocksAction $processBotConstructorBlocksAction,
    ): void {
        $message = Message::query()
            ->with(['channel', 'contactIdentity', 'contact'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message) {
            return;
        }

        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return;
        }

        if ($message->message_kind !== Message::KIND_INBOUND_USER) {
            return;
        }

        $partialFailureLog = $this->resolveLatestUnconsumedPartialFailureLog($message);
        $remainingResolvedRules = $this->resolveRemainingResolvedRulesAfterPartialFailure($partialFailureLog);

        if ($remainingResolvedRules->isNotEmpty()) {
            if (! $this->canResumeRemainingRules($message)) {
                return;
            }

            $channel = $message->channel;
            $contact = $message->contact;

            try {
                $botAutoReplyService->handleResolvedRules(
                    $message,
                    $remainingResolvedRules,
                    $channel,
                    $contact,
                    [
                        'platform' => $channel->platform,
                        'message_id' => $message->id,
                        'provider_event_key' => $message->provider_event_key,
                        'external_message_id' => $message->external_message_id,
                        'auto_reply_mode' => $channel->auto_reply_mode ?? Channel::AUTO_REPLY_MODE_RULES_ONLY,
                        'contact_has_phone' => $contact->phoneNumbers()->exists(),
                        'button_type' => null,
                    ],
                );

                $processBotConstructorBlocksAction->handle($message);
            } catch (Throwable $throwable) {
                $this->reportFailedAutoReply(
                    $message,
                    $throwable,
                    $channelActivityLogger,
                    $resolveAutoReplyRuleAction,
                );

                throw $throwable;
            }

            $channelActivityLogger->info(
                $channel,
                self::RESUME_COMPLETED_EVENT,
                'Повторный запуск автоответа успешно дослал оставшиеся ответы.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $message->id,
                    'provider_event_key' => $message->provider_event_key,
                    'external_message_id' => $message->external_message_id,
                    'resume_failure_log_id' => $partialFailureLog?->id,
                    'remaining_rule_ids' => $remainingResolvedRules
                        ->pluck('id')
                        ->map(fn (mixed $ruleId): int => (int) $ruleId)
                        ->values()
                        ->all(),
                ],
            );

            return;
        }

        if ($message->hasSuccessfulAutoReply()) {
            return;
        }

        if ($message->contact?->isInDataCollection() && ! $this->isAutoReplyOnlyMaxBotStartedEvent($message)) {
            return;
        }

        try {
            $botAutoReplyService->handle($message);
        } catch (Throwable $throwable) {
            $this->reportFailedAutoReply(
                $message,
                $throwable,
                $channelActivityLogger,
                $resolveAutoReplyRuleAction,
            );

            throw $throwable;
        }
    }

    protected function resolveAutoReplySource(Message $message, ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction): string
    {
        if ($message->contact !== null && ! $message->contact->isAutoReplyEnabled()) {
            return 'skipped_contact_disabled';
        }

        $channel = $message->channel;

        if ($channel === null) {
            return 'skipped_no_rule';
        }

        if ($message->contact === null) {
            return 'skipped_no_rule';
        }

        $matchedRules = $resolveAutoReplyRuleAction->handle(
            $channel,
            $message->contact,
            $message->text,
            $message->message_parameter,
        );

        if ($matchedRules->isNotEmpty()) {
            return 'rule';
        }

        return 'skipped_no_rule';
    }

    protected function resolveAutoReplyButtonType(Message $message, ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction): ?string
    {
        $channel = $message->channel;

        if ($channel === null) {
            return null;
        }

        if ($message->contact === null) {
            return null;
        }

        $matchedRules = $resolveAutoReplyRuleAction->handle(
            $channel,
            $message->contact,
            $message->text,
            $message->message_parameter,
        );

        $rule = $matchedRules->first();

        if (! $rule instanceof AutoReplyRule) {
            return null;
        }

        return $rule->getButtonTypeForChannel($channel);
    }

    protected function isAutoReplyOnlyMaxBotStartedEvent(Message $message): bool
    {
        return $message->channel?->platform === Channel::PLATFORM_MAX
            && data_get($message->raw_payload, 'update_type') === 'bot_started'
            && filled($message->message_parameter);
    }

    protected function canResumeRemainingRules(Message $message): bool
    {
        $channel = $message->channel;
        $contact = $message->contact;

        if ($channel === null || $contact === null) {
            return false;
        }

        if (! $contact->isAutoReplyEnabled()) {
            return false;
        }

        if ($contact->isInDataCollection() && ! $this->isAutoReplyOnlyMaxBotStartedEvent($message)) {
            return false;
        }

        return true;
    }

    protected function reportFailedAutoReply(
        Message $message,
        Throwable $throwable,
        ChannelActivityLogger $channelActivityLogger,
        ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction,
    ): void {
        $channel = $message->channel;
        $failedRule = $throwable instanceof AutoReplyDispatchException
            ? $throwable->rule
            : null;
        $buttonType = $throwable instanceof AutoReplyDispatchException
            ? $throwable->buttonType
            : $this->resolveAutoReplyButtonType($message, $resolveAutoReplyRuleAction);
        $autoReplySource = $throwable instanceof AutoReplyDispatchException
            ? 'rule'
            : $this->resolveAutoReplySource($message, $resolveAutoReplyRuleAction);

        if ($channel instanceof Channel) {
            $channel->markError($throwable);

            $channelActivityLogger->error(
                $channel,
                'bot.reply_failed',
                'Не удалось отправить автоответ.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $message->id,
                    'provider_event_key' => $message->provider_event_key,
                    'external_message_id' => $message->external_message_id,
                    'auto_reply_mode' => $channel->auto_reply_mode ?? Channel::AUTO_REPLY_MODE_RULES_ONLY,
                    'auto_reply_source' => $autoReplySource,
                    'button_type' => $buttonType,
                    'rule_id' => $failedRule?->id,
                    'rule_name' => $failedRule?->display_name,
                    'match_scope' => $failedRule?->match_scope,
                    'contact_phone_condition' => $failedRule?->contact_phone_condition,
                    'remaining_rule_ids' => $throwable instanceof AutoReplyDispatchException
                        ? $throwable->remainingRuleIds
                        : [],
                    'error' => $throwable->getMessage(),
                ],
            );
        }

        Log::error('bot auto reply failed', [
            'channel_id' => $channel?->id,
            'platform' => $channel?->platform,
            'message_id' => $message->id,
            'button_type' => $buttonType,
            'rule_id' => $failedRule?->id,
            'rule_name' => $failedRule?->display_name,
            'match_scope' => $failedRule?->match_scope,
            'remaining_rule_ids' => $throwable instanceof AutoReplyDispatchException
                ? $throwable->remainingRuleIds
                : [],
            'error' => $throwable->getMessage(),
        ]);
    }

    /**
     * @return Collection<int, AutoReplyRule>
     */
    protected function resolveRemainingResolvedRulesAfterPartialFailure(
        ?ChannelActivityLog $failureLog,
    ): Collection {
        if (! $failureLog instanceof ChannelActivityLog) {
            return collect();
        }

        $remainingRuleIds = collect(data_get($failureLog->context, 'remaining_rule_ids', []))
            ->map(fn (mixed $ruleId): int => (int) $ruleId)
            ->filter()
            ->values();

        if ($remainingRuleIds->isEmpty()) {
            return collect();
        }

        $resolvedRules = AutoReplyRule::query()
            ->whereIn('id', $remainingRuleIds->all())
            ->get()
            ->keyBy('id');

        return $remainingRuleIds
            ->map(fn (int $ruleId): ?AutoReplyRule => $resolvedRules->get($ruleId))
            ->filter(fn (?AutoReplyRule $rule): bool => $rule instanceof AutoReplyRule)
            ->values();
    }

    protected function resolveLatestUnconsumedPartialFailureLog(Message $message): ?ChannelActivityLog
    {
        if (! $message->hasSuccessfulAutoReply() || $message->channel === null || $message->contact === null) {
            return null;
        }

        $failureLog = ChannelActivityLog::query()
            ->where('channel_id', $message->channel_id)
            ->where('event', 'bot.reply_failed')
            ->where('created_at', '>=', $message->auto_reply_sent_at)
            ->orderByDesc('id')
            ->get()
            ->first(function (ChannelActivityLog $log) use ($message): bool {
                if ((int) data_get($log->context, 'message_id') !== $message->id) {
                    return false;
                }

                $remainingRuleIds = collect(data_get($log->context, 'remaining_rule_ids', []))
                    ->map(fn (mixed $ruleId): int => (int) $ruleId)
                    ->filter()
                    ->values();

                if ($remainingRuleIds->isEmpty()) {
                    return false;
                }

                return true;
            });

        if (! $failureLog instanceof ChannelActivityLog) {
            return null;
        }

        if ($this->hasResumeMarkerForFailure($message, $failureLog->id)) {
            return null;
        }

        return $failureLog;
    }

    protected function hasResumeMarkerForFailure(Message $message, int $failureLogId): bool
    {
        return ChannelActivityLog::query()
            ->where('channel_id', $message->channel_id)
            ->where('event', self::RESUME_COMPLETED_EVENT)
            ->where('id', '>', $failureLogId)
            ->orderByDesc('id')
            ->get()
            ->contains(function (ChannelActivityLog $log) use ($message, $failureLogId): bool {
                return (int) data_get($log->context, 'message_id') === $message->id
                    && (int) data_get($log->context, 'resume_failure_log_id') === $failureLogId;
            });
    }
}
