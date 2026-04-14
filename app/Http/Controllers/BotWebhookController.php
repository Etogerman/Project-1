<?php

namespace App\Http\Controllers;

use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\ScenarioRun;
use App\Services\Bots\BotWebhookRateLimiter;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\DispatchStoredInboundBotMessageAction;
use App\Services\Bots\StoreInboundMessageAction;
use App\Services\Bots\TelegramBotApiService;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class BotWebhookController extends Controller
{
    public function telegram(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
        TelegramBotApiService $telegramBotApiService,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_TELEGRAM,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
            channelActivityLogger: $channelActivityLogger,
            botWebhookRateLimiter: $botWebhookRateLimiter,
            telegramBotApiService: $telegramBotApiService,
        );
    }

    public function max(
        Request $request,
        Channel $channel,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
    ): JsonResponse {
        return $this->handle(
            request: $request,
            channel: $channel,
            expectedPlatform: Channel::PLATFORM_MAX,
            botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
            storeInboundMessageAction: $storeInboundMessageAction,
            dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
            channelActivityLogger: $channelActivityLogger,
            botWebhookRateLimiter: $botWebhookRateLimiter,
        );
    }

    protected function handle(
        Request $request,
        Channel $channel,
        string $expectedPlatform,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
        ChannelActivityLogger $channelActivityLogger,
        BotWebhookRateLimiter $botWebhookRateLimiter,
        ?TelegramBotApiService $telegramBotApiService = null,
    ): JsonResponse {
        abort_unless(
            $channel->is_active
                && $channel->connection_type === Channel::CONNECTION_TYPE_BOT
                && $channel->platform === $expectedPlatform,
            404,
        );

        $secretHeaderName = (string) config("bots.{$expectedPlatform}.webhook_secret_header");
        $expectedSecret = $channel->getWebhookSecret();
        $providedSecret = (string) $request->header($secretHeaderName, '');

        abort_unless(
            filled($expectedSecret) && filled($providedSecret) && hash_equals($expectedSecret, $providedSecret),
            403,
        );

        $retryAfterSeconds = $botWebhookRateLimiter->check($request, $channel);

        if ($retryAfterSeconds !== null) {
            $channelActivityLogger->warning(
                $channel,
                'webhook.rate_limited',
                'Входящий webhook временно ограничен по частоте запросов.',
                [
                    'platform' => $channel->platform,
                    'channel_id' => $channel->id,
                    'request_ip' => $request->ip(),
                    'route' => $request->route()?->getName() ?? $request->path(),
                    'retry_after_seconds' => $retryAfterSeconds,
                    'max_per_minute' => $botWebhookRateLimiter->resolveMaxPerMinute($channel),
                ],
            );

            return response()
                ->json([
                    'ok' => false,
                    'error' => 'rate_limited',
                ], 429)
                ->header('Retry-After', (string) $retryAfterSeconds);
        }

        $payload = $request->json()->all();

        Log::info('bot webhook received', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'update_type' => $payload['update_type'] ?? $this->telegramUpdateType($payload),
        ]);
        $channelActivityLogger->info(
            $channel,
            'webhook.received',
            'Получен входящий webhook.',
            [
                'platform' => $channel->platform,
                'update_type' => $payload['update_type'] ?? $this->telegramUpdateType($payload),
            ],
        );

        $channel->markWebhookReceived();

        if ($expectedPlatform === Channel::PLATFORM_TELEGRAM && isset($payload['callback_query'])) {
            return $this->handleTelegramCallbackQuery(
                channel: $channel,
                payload: $payload,
                telegramBotApiService: $telegramBotApiService,
                botIncomingMessageNormalizer: $botIncomingMessageNormalizer,
                storeInboundMessageAction: $storeInboundMessageAction,
                dispatchStoredInboundBotMessageAction: $dispatchStoredInboundBotMessageAction,
            );
        }

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message === null && $expectedPlatform === Channel::PLATFORM_MAX) {
            $channelActivityLogger->warning(
                $channel,
                'webhook.max_unhandled_payload',
                'Webhook из MAX не удалось распознать в поддерживаемое входящее сообщение.',
                $this->buildMaxUnhandledPayloadContext($payload),
            );
        }

        if ($message !== null) {
            $webhookProcessedAt = now();
            $deliveryLagSeconds = $this->resolveWebhookDeliveryLagSeconds($channel, $message->receivedAt, $webhookProcessedAt);

            if ($deliveryLagSeconds !== null) {
                $channelActivityLogger->info(
                    $channel,
                    'webhook.delayed_received',
                    'Webhook из MAX получен с заметной задержкой.',
                    [
                        'platform' => $channel->platform,
                        'provider_event_key' => $message->providerEventKey,
                        'external_message_id' => $message->externalMessageId,
                        'external_user_id' => $message->externalUserId,
                        'external_chat_id' => $message->externalChatId,
                        'message_received_at' => $message->receivedAt->toIso8601String(),
                        'webhook_processed_at' => $webhookProcessedAt->toIso8601String(),
                        'delivery_lag_seconds' => $deliveryLagSeconds,
                    ],
                );
            }

            $storedResult = $storeInboundMessageAction->handle($channel, $message);

            if ($storedResult !== null) {
                $dispatchStoredInboundBotMessageAction->handle($channel, $storedResult, $deliveryLagSeconds);
            }
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function buildMaxUnhandledPayloadContext(array $payload): array
    {
        $message = is_array(data_get($payload, 'message')) ? data_get($payload, 'message') : [];
        $body = is_array(data_get($message, 'body')) ? data_get($message, 'body') : [];
        $attachments = is_array(data_get($body, 'attachments')) ? data_get($body, 'attachments') : [];
        $attachmentTypes = array_values(array_filter(array_map(function (mixed $attachment): ?string {
            if (! is_array($attachment)) {
                return null;
            }

            $type = trim((string) data_get($attachment, 'type', ''));

            return $type !== '' ? $type : null;
        }, $attachments)));

        $excerpt = [
            'update_type' => data_get($payload, 'update_type'),
            'message_keys' => array_keys($message),
            'body_keys' => array_keys($body),
            'body_contact_keys' => is_array(data_get($body, 'contact')) ? array_keys((array) data_get($body, 'contact')) : [],
            'attachment_types' => $attachmentTypes,
        ];

        $payloadExcerpt = json_encode($excerpt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'platform' => Channel::PLATFORM_MAX,
            'reason' => $this->resolveMaxUnhandledPayloadReason($payload),
            'update_type' => data_get($payload, 'update_type'),
            'message_mid' => data_get($body, 'mid'),
            'message_body_type' => data_get($body, 'type'),
            'has_sender' => is_array(data_get($message, 'sender')),
            'has_sender_user_id' => filled(data_get($message, 'sender.user_id')),
            'has_recipient' => is_array(data_get($message, 'recipient')),
            'has_recipient_user_id' => filled(data_get($message, 'recipient.user_id')),
            'has_recipient_chat_id' => filled(data_get($message, 'recipient.chat_id')),
            'has_body_contact' => is_array(data_get($body, 'contact')),
            'has_attachments' => $attachments !== [],
            'has_vcf_info' => filled(data_get($body, 'vcf_info'))
                || filled(data_get($body, 'payload.vcf_info'))
                || collect($attachments)->contains(function (mixed $attachment): bool {
                    return is_array($attachment)
                        && (
                            filled(data_get($attachment, 'vcf_info'))
                            || filled(data_get($attachment, 'payload.vcf_info'))
                        );
                }),
            'payload_excerpt' => filled($payloadExcerpt)
                ? mb_substr($payloadExcerpt, 0, 1000)
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function resolveMaxUnhandledPayloadReason(array $payload): string
    {
        $updateType = trim((string) data_get($payload, 'update_type', ''));

        if ($updateType === 'bot_started') {
            if (! filled(data_get($payload, 'user.user_id'))) {
                return 'missing_user_id';
            }

            if (! filled($payload['chat_id'] ?? null)) {
                return 'missing_chat_id';
            }

            return 'unknown';
        }

        if ($updateType !== 'message_created') {
            return 'unsupported_update_type';
        }

        $message = data_get($payload, 'message');

        if (! is_array($message)) {
            return 'missing_message_payload';
        }

        if (data_get($message, 'sender.is_bot') === true) {
            return 'sender_is_bot';
        }

        $isDialog = filled($payload['user_locale'] ?? null)
            || filled(data_get($message, 'recipient.user_id'))
            || ! filled(data_get($message, 'recipient.chat_id'));

        if (! $isDialog) {
            return 'not_dialog';
        }

        if (! filled(data_get($message, 'sender.user_id'))) {
            return 'missing_user_id';
        }

        if (! filled(data_get($message, 'recipient.chat_id'))) {
            return 'missing_chat_id';
        }

        return 'unknown';
    }

    protected function resolveWebhookDeliveryLagSeconds(Channel $channel, Carbon $messageReceivedAt, Carbon $webhookProcessedAt): ?int
    {
        if ($channel->platform !== Channel::PLATFORM_MAX) {
            return null;
        }

        $lagSeconds = max(0, $webhookProcessedAt->getTimestamp() - $messageReceivedAt->getTimestamp());
        $thresholdSeconds = (int) config('bots.max.delayed_webhook_threshold_seconds', 60);

        return $lagSeconds > $thresholdSeconds ? $lagSeconds : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleTelegramCallbackQuery(
        Channel $channel,
        array $payload,
        ?TelegramBotApiService $telegramBotApiService,
        BotIncomingMessageNormalizer $botIncomingMessageNormalizer,
        StoreInboundMessageAction $storeInboundMessageAction,
        DispatchStoredInboundBotMessageAction $dispatchStoredInboundBotMessageAction,
    ): JsonResponse {
        $callbackQueryId = trim((string) data_get($payload, 'callback_query.id', ''));

        if ($telegramBotApiService instanceof TelegramBotApiService && $callbackQueryId !== '') {
            $telegramBotApiService->answerCallbackQuery($channel, $callbackQueryId);
        }

        $callback = $this->normalizeTelegramDataCollectionCallback($payload);

        if ($callback !== null) {
            if (! $this->isTelegramDataCollectionCallbackActionable($channel, $payload, $callback['field'])) {
                return response()->json([
                    'ok' => true,
                ]);
            }
        } elseif (! $this->isTelegramScenarioCallbackActionable($channel, $payload)) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $message = $botIncomingMessageNormalizer->normalize($channel, $payload);

        if ($message === null) {
            return response()->json([
                'ok' => true,
            ]);
        }

        $storedResult = $storeInboundMessageAction->handle($channel, $message);

        if ($storedResult !== null) {
            $dispatchStoredInboundBotMessageAction->handle($channel, $storedResult);
        }

        return response()->json([
            'ok' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function normalizeTelegramDataCollectionCallback(array $payload): ?array
    {
        $data = trim((string) data_get($payload, 'callback_query.data', ''));

        foreach ([
            'age_range:' => \App\Models\Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'russian_region_confirm:' => \App\Models\Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM,
        ] as $prefix => $field) {
            if (! str_starts_with($data, $prefix)) {
                continue;
            }

            $value = trim(substr($data, strlen($prefix)));

            if ($value === '') {
                return null;
            }

            return [
                'field' => $field,
                'value' => $value,
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isTelegramDataCollectionCallbackActionable(Channel $channel, array $payload, string $field): bool
    {
        $externalUserId = trim((string) data_get($payload, 'callback_query.from.id', ''));

        if ($externalUserId === '') {
            return false;
        }

        $identity = ContactIdentity::query()
            ->with('contact')
            ->where('channel_id', $channel->id)
            ->where('external_user_id', $externalUserId)
            ->first();

        return $identity?->contact?->isInDataCollection() === true
            && $identity->contact->data_collection_current_field === $field;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function isTelegramScenarioCallbackActionable(Channel $channel, array $payload): bool
    {
        $callbackData = trim((string) data_get($payload, 'callback_query.data', ''));

        if ($callbackData === '') {
            return false;
        }

        if (preg_match('/^scenario:warmup:(\d+):([a-z_]+)$/', $callbackData, $matches)) {
            $run = $this->findTelegramScenarioRun($channel, $payload, (int) ($matches[1] ?? 0));

            return $run instanceof ScenarioRun
                && $this->activeRunSupportsTelegramScenarioCallback($run, $callbackData);
        }

        if (! preg_match('/^scenario:(\d+):([A-Za-z0-9_-]{1,32})$/', $callbackData, $matches)) {
            return false;
        }

        $run = $this->findTelegramScenarioRun($channel, $payload, (int) ($matches[1] ?? 0));

        if (! $run instanceof ScenarioRun) {
            return false;
        }

        return $this->activeRunSupportsTelegramScenarioCallback($run, $callbackData);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function findTelegramScenarioRun(Channel $channel, array $payload, int $runId): ?ScenarioRun
    {
        $externalUserId = trim((string) data_get($payload, 'callback_query.from.id', ''));
        $externalChatId = trim((string) data_get($payload, 'callback_query.message.chat.id', ''));

        if ($runId <= 0 || $externalUserId === '' || $externalChatId === '') {
            return null;
        }

        $run = ScenarioRun::query()
            ->with('dialog.currentContactIdentity')
            ->active()
            ->whereKey($runId)
            ->first();

        if (! $run instanceof ScenarioRun || ! $run->dialog) {
            return null;
        }

        if (
            (int) $run->dialog->channel_id !== (int) $channel->id
            || (string) ($run->dialog->external_chat_id ?? '') !== $externalChatId
            || (string) ($run->dialog->currentContactIdentity?->external_user_id ?? '') !== $externalUserId
        ) {
            return null;
        }

        return $run;
    }

    protected function activeRunSupportsTelegramScenarioCallback(ScenarioRun $run, string $callbackData): bool
    {
        $runtime = app(ScenarioRegistry::class)->makeRuntime($run->scenario_code);

        if ($runtime === null) {
            return false;
        }

        return $runtime->supportsTelegramCallbackContinuation($run, $callbackData);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function telegramUpdateType(array $payload): ?string
    {
        if (isset($payload['callback_query'])) {
            return 'callback_query';
        }

        if (isset($payload['my_chat_member'])) {
            return 'my_chat_member';
        }

        return isset($payload['message']) ? 'message' : null;
    }
}
