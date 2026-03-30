<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Services\Bots\ChannelActivityLogger;
use App\Services\Bots\MaxBotApiService;
use App\Services\Bots\StorePhoneCaptureConfirmationAction;
use App\Services\Bots\TelegramBotApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class ProcessPhoneCaptureFollowUpJob implements ShouldQueue
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
            (new WithoutOverlapping("phone-capture-followup:message:{$this->inboundMessageId}"))->expireAfter(180),
        ];
    }

    public function handle(
        TelegramBotApiService $telegramBotApiService,
        MaxBotApiService $maxBotApiService,
        StorePhoneCaptureConfirmationAction $storePhoneCaptureConfirmationAction,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        $message = Message::query()
            ->with(['channel', 'contact', 'contactIdentity'])
            ->find($this->inboundMessageId);

        if (! $message instanceof Message) {
            return;
        }

        if ($message->direction !== Message::DIRECTION_INBOUND) {
            return;
        }

        if ($message->message_kind !== Message::KIND_INBOUND_CONTACT_SHARE) {
            return;
        }

        $channel = $message->channel;

        if (! $channel instanceof Channel || ! $channel->is_active) {
            return;
        }

        if ($this->confirmationAlreadyExists($message)) {
            $channelActivityLogger->info(
                $channel,
                'contact.phone_capture_confirmation_skipped',
                'Подтверждение после получения номера уже существует.',
                $this->baseContext($message, $channel),
            );
        } else {
            $confirmationText = (string) config('bots.phone_capture_confirmation_text');

            try {
                $deliveryResult = match ($channel->platform) {
                    Channel::PLATFORM_TELEGRAM => $telegramBotApiService->sendTextMessage(
                        $channel,
                        $message->external_chat_id,
                        $message->contactIdentity?->external_user_id,
                        $confirmationText,
                        ['remove_keyboard' => true],
                    ),
                    Channel::PLATFORM_MAX => $maxBotApiService->sendTextMessage(
                        $channel,
                        $message->external_chat_id,
                        $message->contactIdentity?->external_user_id,
                        $confirmationText,
                    ),
                    default => throw new InvalidArgumentException("Unsupported bot platform [{$channel->platform}]."),
                };

                $storePhoneCaptureConfirmationAction->handle($channel, $message, $deliveryResult);
                $channel->markReplySent();

                $channelActivityLogger->info(
                    $channel,
                    'contact.phone_capture_confirmed',
                    'Подтверждение после получения номера отправлено.',
                    $this->baseContext($message, $channel),
                );
            } catch (Throwable $throwable) {
                $channel->markError($throwable);

                $channelActivityLogger->error(
                    $channel,
                    'contact.phone_capture_confirmation_failed',
                    'Не удалось отправить подтверждение после получения номера.',
                    $this->baseContext($message, $channel) + [
                        'error' => $throwable->getMessage(),
                    ],
                );

                Log::error('phone capture confirmation failed', [
                    'channel_id' => $channel->id,
                    'platform' => $channel->platform,
                    'message_id' => $message->id,
                    'error' => $throwable->getMessage(),
                ]);

                throw $throwable;
            }
        }

        $this->maybeStartDataCollection($message, $channel, $channelActivityLogger);
    }

    protected function confirmationAlreadyExists(Message $message): bool
    {
        return $message->replies()
            ->where('message_kind', Message::KIND_OUTBOUND_PHONE_CAPTURE_CONFIRMATION)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    protected function baseContext(Message $message, Channel $channel): array
    {
        return [
            'contact_id' => $message->contact_id,
            'channel_id' => $channel->id,
            'message_id' => $message->id,
            'platform' => $channel->platform,
            'button_type' => 'request_phone',
        ];
    }

    protected function maybeStartDataCollection(
        Message $message,
        Channel $channel,
        ChannelActivityLogger $channelActivityLogger,
    ): void {
        if (! (bool) config('bots.data_collection.enabled', true)) {
            return;
        }

        $contact = $message->contact;

        if (! $contact instanceof Contact) {
            return;
        }

        if ($contact->isInDataCollection() || filled($contact->first_name)) {
            return;
        }

        $contact->startDataCollection(Contact::DATA_COLLECTION_FIELD_FIRST_NAME);

        $channelActivityLogger->info(
            $channel,
            'contact.data_collection_started',
            'После получения номера запущен сбор профиля.',
            [
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'message_id' => $message->id,
                'current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            ],
        );

        ProcessDataCollectionQuestionJob::dispatch($message->id);
    }
}
