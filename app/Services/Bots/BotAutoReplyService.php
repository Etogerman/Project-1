<?php

namespace App\Services\Bots;

use App\Models\Message;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

class BotAutoReplyService
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction,
        protected StoreOutboundAutoReplyMessageAction $storeOutboundAutoReplyMessageAction,
        protected TelegramBotApiService $telegramBotApiService,
        protected MaxBotApiService $maxBotApiService,
    ) {}

    public function handle(Message $storedMessage): void
    {
        if ($storedMessage->hasSuccessfulAutoReply()) {
            return;
        }

        $storedMessage->loadMissing(['channel', 'contactIdentity', 'contact']);

        $channel = $storedMessage->channel;
        $contact = $storedMessage->contact;

        if ($channel === null) {
            throw new InvalidArgumentException("Inbound message [{$storedMessage->id}] does not have a channel.");
        }

        if ($contact !== null && ! $contact->isAutoReplyEnabled()) {
            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_skipped_contact_disabled',
                'Автоответ отключён для этого контакта.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                ],
            );

            return;
        }

        $matchedRule = $this->resolveAutoReplyRuleAction->handle($channel, $storedMessage->text);
        $hasActiveRules = $channel->autoReplyRules()->active()->exists();

        if ($matchedRule !== null) {
            $replyText = (string) $matchedRule->reply_text;

            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_rule_matched',
                'Выбрано правило автоответа.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'rule_id' => $matchedRule->id,
                    'keyword' => $matchedRule->keyword,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                ],
            );
        } elseif ($hasActiveRules) {
            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_skipped_no_rule',
                'Автоответ не отправлен: правило не найдено.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                    'message_text' => $storedMessage->text,
                ],
            );

            return;
        } else {
            $replyText = (string) config('bots.default_auto_reply_text');

            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_legacy_default_used',
                'Автоответ отправлен через legacy fallback.',
                [
                    'platform' => $channel->platform,
                    'message_id' => $storedMessage->id,
                    'provider_event_key' => $storedMessage->provider_event_key,
                    'external_message_id' => $storedMessage->external_message_id,
                ],
            );
        }

        $externalChatId = $storedMessage->external_chat_id;
        $externalUserId = $storedMessage->contactIdentity?->external_user_id;

        Log::info('bot auto reply started', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'external_chat_id' => $externalChatId,
            'external_user_id' => $externalUserId,
            'rule_id' => $matchedRule?->id,
        ]);

        $deliveryResult = match ($channel->platform) {
            \App\Models\Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
            ),
            \App\Models\Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
            ),
            default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
        };

        $this->storeOutboundAutoReplyMessageAction->handle($channel, $storedMessage, $deliveryResult);

        $channel->markReplySent();

        Log::info('bot auto reply sent', [
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'external_chat_id' => $externalChatId,
            'external_user_id' => $externalUserId,
            'outbound_external_message_id' => $deliveryResult->externalMessageId,
        ]);
        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_sent',
            'Автоответ отправлен.',
            [
                'platform' => $channel->platform,
                'message_id' => $storedMessage->id,
                'external_chat_id' => $externalChatId,
                'external_user_id' => $externalUserId,
                'outbound_external_message_id' => $deliveryResult->externalMessageId,
                'rule_id' => $matchedRule?->id,
            ],
        );
    }
}
