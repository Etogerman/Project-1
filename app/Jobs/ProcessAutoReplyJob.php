<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Bots\BotAutoReplyService;
use App\Services\Bots\ChannelActivityLogger;
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

    public function handle(BotAutoReplyService $botAutoReplyService, ChannelActivityLogger $channelActivityLogger): void
    {
        $message = Message::query()
            ->with(['channel', 'contactIdentity'])
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
                        'error' => $throwable->getMessage(),
                    ],
                );
            }

            Log::error('bot auto reply failed', [
                'channel_id' => $channel?->id,
                'platform' => $channel?->platform,
                'message_id' => $message->id,
                'error' => $throwable->getMessage(),
            ]);

            throw $throwable;
        }
    }
}
