<?php

namespace App\Services\Bots;

use App\Models\Channel;
use App\Models\ChannelActivityLog;

class ChannelActivityLogger
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function info(Channel $channel, string $event, string $message, array $context = []): void
    {
        $this->write($channel, 'info', $event, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(Channel $channel, string $event, string $message, array $context = []): void
    {
        $this->write($channel, 'error', $event, $message, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function write(Channel $channel, string $level, string $event, string $message, array $context = []): void
    {
        ChannelActivityLog::query()->create([
            'channel_id' => $channel->id,
            'level' => $level,
            'event' => $event,
            'message' => $message,
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ]);
    }
}
