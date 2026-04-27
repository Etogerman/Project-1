<?php

namespace App\Services\Bots;

use App\Models\AutoReplyRule;
use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class BotAutoReplyService
{
    public function __construct(
        protected ChannelActivityLogger $channelActivityLogger,
        protected ResolveAutoReplyRuleAction $resolveAutoReplyRuleAction,
        protected SendBotDialogTextAction $sendBotDialogTextAction,
        protected StoreOutboundAutoReplyMessageAction $storeOutboundAutoReplyMessageAction,
    ) {}

    public function handle(Message $storedMessage): void
    {
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

        $matchedRules = $contact instanceof Contact
            ? $this->resolveAutoReplyRuleAction->handle(
                $channel,
                $contact,
                $storedMessage->text,
                $storedMessage->message_parameter,
            )
            : collect();

        if ($matchedRules->isEmpty()) {
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

        $this->handleResolvedRules(
            $storedMessage,
            $matchedRules,
            $channel,
            $contact,
            $baseContext,
        );
    }

    public function handleResolvedRule(
        Message $storedMessage,
        AutoReplyRule $matchedRule,
        ?Dialog $routeDialog = null,
    ): void {
        $storedMessage->loadMissing(['channel', 'contactIdentity', 'contact']);
        $routeDialog?->loadMissing(['channel', 'currentContactIdentity']);

        $channel = $routeDialog?->channel ?? $storedMessage->channel;
        $contact = $routeDialog?->contact ?? $storedMessage->contact;

        if ($channel === null) {
            throw new InvalidArgumentException("Inbound message [{$storedMessage->id}] does not have a channel.");
        }

        if (! $contact instanceof Contact) {
            throw new InvalidArgumentException("Inbound message [{$storedMessage->id}] does not have a contact.");
        }

        $autoReplyMode = $channel->auto_reply_mode ?? \App\Models\Channel::AUTO_REPLY_MODE_RULES_ONLY;
        $contactHasPhone = $contact->phoneNumbers()->exists();
        $baseContext = [
            'platform' => $channel->platform,
            'message_id' => $storedMessage->id,
            'provider_event_key' => $storedMessage->provider_event_key,
            'external_message_id' => $storedMessage->external_message_id,
            'auto_reply_mode' => $autoReplyMode,
            'contact_has_phone' => $contactHasPhone,
            'button_type' => null,
        ];

        if (! $contact->isAutoReplyEnabled()) {
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

        $this->dispatchResolvedRule($storedMessage, $matchedRule, $channel, $contact, $baseContext, $routeDialog);
    }

    /**
     * @param  Collection<int, AutoReplyRule>  $matchedRules
     * @param  array<string, mixed>  $baseContext
     */
    public function handleResolvedRules(
        Message $storedMessage,
        Collection $matchedRules,
        \App\Models\Channel $channel,
        Contact $contact,
        array $baseContext,
        ?Dialog $routeDialog = null,
    ): void {
        foreach ($matchedRules->values() as $index => $matchedRule) {
            try {
                $this->dispatchResolvedRule($storedMessage, $matchedRule, $channel, $contact, $baseContext, $routeDialog);
            } catch (AutoReplyDispatchException $exception) {
                throw new AutoReplyDispatchException(
                    $exception->rule,
                    $exception->buttonType,
                    $exception->getPrevious() ?? $exception,
                    $matchedRules
                        ->slice($index)
                        ->pluck('id')
                        ->map(fn (mixed $ruleId): int => (int) $ruleId)
                        ->values()
                        ->all(),
                );
            }
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function buildTelegramReplyMarkup(AutoReplyRule $matchedRule, \App\Models\Channel $channel): ?array
    {
        $buttonType = $matchedRule->getButtonTypeForChannel($channel);

        if ($buttonType === AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT) {
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

        if ($buttonType !== AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD) {
            return null;
        }

        $buttonText = $matchedRule->getButtonTextForChannel($channel);
        $buttonUrl = $matchedRule->getButtonUrlForChannel($channel);

        if (! filled($buttonText) || ! filled($buttonUrl)) {
            return null;
        }

        return [
            'inline_keyboard' => [
                [
                    [
                        'text' => $buttonText,
                        'url' => $buttonUrl,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    protected function buildMaxAttachments(AutoReplyRule $matchedRule, \App\Models\Channel $channel): ?array
    {
        $buttonType = $matchedRule->getButtonTypeForChannel($channel);

        if ($buttonType === AutoReplyRule::BUTTON_TYPE_SHARE_CONTACT) {
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

        if ($buttonType !== AutoReplyRule::BUTTON_TYPE_INLINE_KEYBOARD) {
            return null;
        }

        $buttonText = $matchedRule->getButtonTextForChannel($channel);
        $buttonUrl = $matchedRule->getButtonUrlForChannel($channel);

        if (! filled($buttonText) || ! filled($buttonUrl)) {
            return null;
        }

        return [[
            'type' => 'inline_keyboard',
            'payload' => [
                'buttons' => [[[
                    'type' => 'link',
                    'text' => $buttonText,
                    'url' => $buttonUrl,
                ]]],
            ],
        ]];
    }

    protected function resolveButtonType(AutoReplyRule $matchedRule, \App\Models\Channel $channel): ?string
    {
        return $matchedRule->getButtonTypeForChannel($channel);
    }

    /**
     * @param  array<string, mixed>  $baseContext
     */
    protected function dispatchResolvedRule(
        Message $storedMessage,
        AutoReplyRule $matchedRule,
        \App\Models\Channel $channel,
        Contact $contact,
        array $baseContext,
        ?Dialog $routeDialog = null,
    ): void {
        $routeDialog?->loadMissing(['currentContactIdentity']);

        $replyText = (string) $matchedRule->reply_text;
        $autoReplySource = 'rule';
        $buttonType = $this->resolveButtonType($matchedRule, $channel);
        $externalChatId = $routeDialog?->external_chat_id ?? $storedMessage->external_chat_id;
        $externalUserId = $routeDialog?->currentContactIdentity?->external_user_id ?? $storedMessage->contactIdentity?->external_user_id;
        $autoReplyMode = $channel->auto_reply_mode ?? \App\Models\Channel::AUTO_REPLY_MODE_RULES_ONLY;
        $contactHasPhone = $contact->phoneNumbers()->exists();

        try {
            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_rule_matched',
                'Выбрано правило автоответа.',
                $baseContext + [
                    'auto_reply_source' => $autoReplySource,
                    'button_type' => $buttonType,
                    'rule_id' => $matchedRule->id,
                    'rule_name' => $matchedRule->display_name,
                    'match_scope' => $matchedRule->match_scope,
                    'contact_phone_condition' => $matchedRule->contact_phone_condition,
                    'keyword' => $matchedRule->keyword,
                ],
            );

            Log::info('bot auto reply started', [
                'channel_id' => $channel->id,
                'platform' => $channel->platform,
                'message_id' => $storedMessage->id,
                'external_chat_id' => $externalChatId,
                'external_user_id' => $externalUserId,
                'auto_reply_mode' => $autoReplyMode,
                'auto_reply_source' => $autoReplySource,
                'button_type' => $buttonType,
                'rule_id' => $matchedRule->id,
                'rule_name' => $matchedRule->display_name,
                'match_scope' => $matchedRule->match_scope,
                'contact_phone_condition' => $matchedRule->contact_phone_condition,
                'contact_has_phone' => $contactHasPhone,
            ]);

            $sendResult = $routeDialog instanceof Dialog
                ? $this->sendBotDialogTextAction->handleDialog(
                    $routeDialog,
                    $replyText,
                    $this->buildTelegramReplyMarkup($matchedRule, $channel),
                    $this->buildMaxAttachments($matchedRule, $channel),
                )
                : $this->sendBotDialogTextAction->handleMessage(
                    $storedMessage,
                    $replyText,
                    telegramReplyMarkup: $this->buildTelegramReplyMarkup($matchedRule, $channel),
                    maxAttachments: $this->buildMaxAttachments($matchedRule, $channel),
                );

            if (! $sendResult->wasSent() || $sendResult->deliveryResult === null) {
                $this->channelActivityLogger->info(
                    $channel,
                    'bot.reply_skipped_dialog_not_sendable',
                    'Автоответ не отправлен: диалог сейчас недоступен для отправки.',
                    $baseContext + [
                        'auto_reply_source' => $autoReplySource,
                        'button_type' => $buttonType,
                        'match_scope' => $matchedRule->match_scope,
                        'contact_phone_condition' => $matchedRule->contact_phone_condition,
                        'rule_id' => $matchedRule->id,
                        'rule_name' => $matchedRule->display_name,
                        'dialog_id' => $sendResult->dialog?->id ?? $routeDialog?->id ?? $storedMessage->dialog_id,
                        'external_chat_id' => $sendResult->dialog?->external_chat_id ?? $externalChatId,
                        'external_user_id' => $sendResult->dialog?->currentContactIdentity?->external_user_id ?? $externalUserId,
                        'route_status_code' => $sendResult->routeStatus->code,
                        'blocked_reason' => $sendResult->routeStatus->blockedReason,
                    ],
                );

                return;
            }

            $deliveryResult = $sendResult->deliveryResult;
            $routeDialog = $sendResult->dialog ?? $routeDialog;

            $this->storeOutboundAutoReplyMessageAction->handle(
                $channel,
                $storedMessage,
                $deliveryResult,
                $matchedRule,
                $routeDialog,
            );

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
                'match_scope' => $matchedRule->match_scope,
                'contact_phone_condition' => $matchedRule->contact_phone_condition,
                'contact_has_phone' => $contactHasPhone,
                'outbound_external_message_id' => $deliveryResult->externalMessageId,
                'rule_id' => $matchedRule->id,
                'rule_name' => $matchedRule->display_name,
            ]);
            $this->channelActivityLogger->info(
                $channel,
                'bot.reply_sent',
                'Автоответ отправлен.',
                $baseContext + [
                    'auto_reply_source' => $autoReplySource,
                    'button_type' => $buttonType,
                    'match_scope' => $matchedRule->match_scope,
                    'contact_phone_condition' => $matchedRule->contact_phone_condition,
                    'external_chat_id' => $externalChatId,
                    'external_user_id' => $externalUserId,
                    'outbound_external_message_id' => $deliveryResult->externalMessageId,
                    'rule_id' => $matchedRule->id,
                    'rule_name' => $matchedRule->display_name,
                ],
            );
        } catch (Throwable $throwable) {
            throw new AutoReplyDispatchException($matchedRule, $buttonType, $throwable);
        }
    }
}
