<?php

namespace App\Services\Bots;

use App\Data\Bots\BotMetadata;
use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class SyncChannelBotMetadataAction
{
    public function __construct(
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Channel $channel): Channel
    {
        $this->guardChannel($channel);

        $metadata = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->fetchBotMetadata($channel),
            Channel::PLATFORM_MAX => $this->maxBotApiService->fetchBotMetadata($channel),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        $channel->fill($this->metadataToAttributes($metadata))->save();

        Log::info('bot metadata synced', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'bot_external_id' => $metadata->externalId,
            'bot_username' => $metadata->username,
        ]);

        return $channel->refresh();
    }

    protected function guardChannel(Channel $channel): void
    {
        if ($channel->connection_type !== Channel::CONNECTION_TYPE_BOT) {
            throw new InvalidArgumentException('Метаданные поддерживаются только для bot-каналов.');
        }

        if (! array_key_exists($channel->platform, Channel::platformOptions())) {
            throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}].");
        }
    }

    /**
     * @return array<string, string|null>
     */
    protected function metadataToAttributes(BotMetadata $metadata): array
    {
        return [
            'bot_external_id' => $metadata->externalId,
            'bot_username' => $metadata->username,
            'bot_name' => $metadata->name,
            'bot_profile_url' => $metadata->profileUrl,
        ];
    }
}
