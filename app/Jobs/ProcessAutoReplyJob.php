<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\ResolveAutoReplyRuleAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessAutoReplyJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(public int $inboundMessageId) {}

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
    ): void
    {
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

        if ($message->hasSuccessfulAutoReply()) {
            return;
        }

        try {
            $botAutoReplyService->handle($message);
        } catch (Throwable $throwable) {
            $channel = $message->channel;

            if ($channel !== null) {
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
                        'auto_reply_mode' => $channel->auto_reply_mode ?? \App\Models\Channel::AUTO_REPLY_MODE_LEGACY_DEFAULT,
                        'auto_reply_source' => $this->resolveAutoReplySource($message, $resolveAutoReplyRuleAction),
                        'button_type' => $this->resolveAutoReplyButtonType($message, $resolveAutoReplyRuleAction),
                        'error' => $throwable->getMessage(),
                    ],
                );
            }

            Log::error('bot auto reply failed', [
                'channel_id' => $channel?->id,
                'platform' => $channel?->platform,
                'message_id' => $message->id,
                'button_type' => $this->resolveAutoReplyButtonType($message, $resolveAutoReplyRuleAction),
                'error' => $throwable->getMessage(),
            ]);

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
            return 'legacy_default';
        }

        if ($resolveAutoReplyRuleAction->handle($channel, $message->text) !== null) {
            return 'rule';
        }

        return $channel->usesLegacyAutoReplyFallback() ? 'legacy_default' : 'skipped_no_rule';
    }

    protected function resolveAutoReplyButtonType(Message $message, ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction): ?string
    {
        $channel = $message->channel;

        if ($channel === null) {
            return null;
        }

        $rule = $resolveAutoReplyRuleAction->handle($channel, $message->text);

        if ($rule === null) {
            return null;
        }

        return match ($channel->platform) {
            \App\Models\Channel::PLATFORM_TELEGRAM => $rule->telegram_button_type,
            \App\Models\Channel::PLATFORM_MAX => $rule->max_button_type,
            default => null,
        };
    }
}
