<?php

namespace App\Services\Bots;

use App\Models\Channel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class RegisterChannelWebhookAction
{
    public function __construct(
        protected ChannelWebhookUrlGenerator $channelWebhookUrlGenerator,
        protected ChannelActivityLogger $channelActivityLogger,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
        protected SyncChannelBotMetadataAction $syncChannelBotMetadataAction,
        protected CheckChannelConnectionAction $checkChannelConnectionAction,
    ) {}

    public function handle(Channel $channel): void
    {
        $this->guardChannel($channel);

        try {
            $webhookUrl = $this->channelWebhookUrlGenerator->ensureHttps($channel);
            $webhookSecret = $this->ensureWebhookSecret($channel);

            Log::info('bot webhook registration started', [
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'webhook_url' => $webhookUrl,
            ]);
            $this->channelActivityLogger->info(
                $channel,
                'webhook.registration_started',
                'Начата регистрация webhook.',
                [
                    'platform' => $channel->platform,
                    'webhook_url' => $webhookUrl,
                ],
            );

            match ($channel->platform) {
                Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->registerWebhook($channel, $webhookUrl, $webhookSecret),
                Channel::PLATFORM_MAX => $this->maxBotApiService->registerWebhook($channel, $webhookUrl, $webhookSecret),
                default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
            };

            $this->syncChannelBotMetadataAction->handle($channel);
            if ($channel->supportsConnectionCheck()) {
                $this->checkChannelConnectionAction->handle(
                    $channel->fresh() ?? $channel,
                    'Webhook не подтверждён после регистрации',
                );
            }
            $channel->clearOperationalError();

            Log::info('bot webhook registration completed', [
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'webhook_url' => $webhookUrl,
            ]);
            $this->channelActivityLogger->info(
                $channel,
                'webhook.registration_completed',
                'Webhook зарегистрирован.',
                [
                    'platform' => $channel->platform,
                    'webhook_url' => $webhookUrl,
                ],
            );
        } catch (\Throwable $throwable) {
            $channel->markError($throwable);
            $this->channelActivityLogger->error(
                $channel,
                'webhook.registration_failed',
                'Не удалось зарегистрировать webhook.',
                [
                    'platform' => $channel->platform,
                    'error' => $throwable->getMessage(),
                ],
            );

            throw $throwable;
        }
    }

    protected function guardChannel(Channel $channel): void
    {
        if (! $channel->is_active) {
            throw new InvalidArgumentException('Webhook можно регистрировать только для активного канала.');
        }

        if ($channel->connection_type !== Channel::CONNECTION_TYPE_BOT) {
            throw new InvalidArgumentException('Webhook поддерживается только для bot-каналов.');
        }

        if (! array_key_exists($channel->platform, Channel::platformOptions())) {
            throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}].");
        }
    }

    protected function ensureWebhookSecret(Channel $channel): string
    {
        $secret = $channel->getWebhookSecret();

        if (filled($secret)) {
            return $secret;
        }

        $secret = Str::random((int) config('bots.webhook_secret_length', 40));

        $channel
            ->putCredential(Channel::CREDENTIAL_WEBHOOK_SECRET, $secret)
            ->save();

        return $secret;
    }
}
