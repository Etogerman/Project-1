<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\Contact;
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

        $autoReplyMode = $channel->auto_reply_mode ?? \App\Models\Channel::AUTO_REPLY_MODE_RULES_ONLY;
        $buttonType = null;
        $contactHasPhone = $contact instanceof Contact
            ? $contact->phoneNumbers()->exists()
            : false;
        $baseContext = [
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'provider_event_key' => $storedMessage->provider_event_key,
            'external_message_id' => $storedMessage->external_message_id,
            'auto_reply_mode' => $autoReplyMode,
            'contact_has_phone' => $contactHasPhone,
            'button_type' => $buttonType,
        ];

        if ($contact !== null && ! $contact->isAutoReplyEnabled()) {
            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_skipped_contact_disabled',
                'Автоответ отключён для этого контакта.',
                $baseContext + [
                    'auto_reply_source' => 'skipped_contact_disabled',
                ],
            );

            return;
        }

        $matchedRule = $contact instanceof Contact
            ? $this->resolveAutoReplyRuleAction->handle(
                $channel,
                $contact,
                $storedMessage->text,
                $storedMessage->message_parameter,
            )
            : null;

        if ($matchedRule !== null) {
            $replyText = (string) $matchedRule->reply_text;
            $autoReplySource = 'rule';
            $buttonType = $this->resolveButtonType($matchedRule, $channel->platform);

            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_rule_matched',
                'Выбрано правило автоответа.',
                $baseContext + [
                    'auto_reply_source' => $autoReplySource,
                    'button_type' => $buttonType,
                    'rule_id' => $matchedRule->id,
                    'match_scope' => $matchedRule->match_scope,
                    'contact_phone_condition' => $matchedRule->contact_phone_condition,
                    'keyword' => $matchedRule->keyword,
                ],
            );
        } else {
            $autoReplySource = 'skipped_no_rule';

            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_skipped_no_rule',
                'Автоответ не отправлен: правило не найдено.',
                $baseContext + [
                    'auto_reply_source' => $autoReplySource,
                    'match_scope' => null,
                    'contact_phone_condition' => null,
                    'message_text' => $storedMessage->text,
                    'message_parameter' => $storedMessage->message_parameter,
                ],
            );

            return;
        }

        $externalChatId = $storedMessage->external_chat_id;
        $externalUserId = $storedMessage->contactIdentity?->external_user_id;

        Log::info('bot auto reply started', [
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'message_id' => $storedMessage->id,
                'external_chat_id' => $externalChatId,
                'external_user_id' => $externalUserId,
                'auto_reply_mode' => $autoReplyMode,
                'auto_reply_source' => $autoReplySource,
                'button_type' => $buttonType,
                'rule_id' => $matchedRule?->id,
                'match_scope' => $matchedRule?->match_scope,
                'contact_phone_condition' => $matchedRule?->contact_phone_condition,
                'contact_has_phone' => $contactHasPhone,
        ]);

        $deliveryResult = match ($channel->platform) {
            \App\Models\Channel::PLATFORM_TELEGRAM => $this->telegramBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
                $this->buildTelegramReplyMarkup($matchedRule),
            ),
            \App\Models\Channel::PLATFORM_MAX => $this->maxBotApiService->sendTextMessage(
                $channel,
                $externalChatId,
                $externalUserId,
                $replyText,
                $this->buildMaxAttachments($matchedRule),
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
            'auto_reply_mode' => $autoReplyMode,
            'auto_reply_source' => $autoReplySource,
            'button_type' => $buttonType,
            'match_scope' => $matchedRule?->match_scope,
            'contact_phone_condition' => $matchedRule?->contact_phone_condition,
            'contact_has_phone' => $contactHasPhone,
            'outbound_external_message_id' => $deliveryResult->externalMessageId,
        ]);
        $this->channelActivityLogger->info(
            $channel,
            'bot.reply_sent',
            'Автоответ отправлен.',
            $baseContext + [
                'auto_reply_source' => $autoReplySource,
                'button_type' => $buttonType,
                'match_scope' => $matchedRule?->match_scope,
                'contact_phone_condition' => $matchedRule?->contact_phone_condition,
                'external_chat_id' => $externalChatId,
                'external_user_id' => $externalUserId,
                'outbound_external_message_id' => $deliveryResult->externalMessageId,
                'rule_id' => $matchedRule?->id,
            ],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildTelegramReplyMarkup(?AutoReplyRule $matchedRule): ?array
    {
        if (
            ! $matchedRule instanceof AutoReplyRule
            || $matchedRule->telegram_button_type !== AutoReplyRule::TELEGRAM_BUTTON_TYPE_REQUEST_PHONE
        ) {
            return null;
        }

        return [
            'keyboard' => [
                [
                    [
                        'text' => 'Поделиться номером телефона',
                        'request_contact' => true,
                    ],
                ],
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function buildMaxAttachments(?AutoReplyRule $matchedRule): ?array
    {
        if (
            ! $matchedRule instanceof AutoReplyRule
            || $matchedRule->max_button_type !== AutoReplyRule::MAX_BUTTON_TYPE_REQUEST_PHONE
        ) {
            return null;
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [[[
                    'type' => 'request_contact',
                    'text' => 'Поделиться номером телефона',
                ]]],
            ],
        ]];
    }

    protected function resolveButtonType(AutoReplyRule $matchedRule, string $platform): ?string
    {
        return match ($platform) {
            \App\Models\Channel::PLATFORM_TELEGRAM => $matchedRule->telegram_button_type,
            \App\Models\Channel::PLATFORM_MAX => $matchedRule->max_button_type,
            default => null,
        };
    }
}
