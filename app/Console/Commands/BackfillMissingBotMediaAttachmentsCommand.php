<?php

namespace App\Console\Commands;

use App\Data\Bots\IncomingBotMessage;
use App\Models\Channel;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Services\Bots\BotIncomingMessageNormalizer;
use App\Services\Bots\DownloadBotMessageAttachmentsAction;
use App\Services\Bots\SyncBotInboundMessageAttachmentsAction;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

class BackfillMissingBotMediaAttachmentsCommand extends Command
{
    protected $signature = 'bot-media:backfill-missing-attachments
        {--force : Create missing attachment records instead of dry-run}
        {--download : After --force, run the standard local download pipeline for affected messages}
        {--channel= : Limit to one channel ID}
        {--message=* : Limit to one or more message IDs}
        {--limit=500 : Maximum inbound bot messages to inspect}';

    protected $description = 'Backfill normalized bot media attachments from existing inbound raw payloads.';

    public function __construct(
        private readonly BotIncomingMessageNormalizer $normalizer,
        private readonly SyncBotInboundMessageAttachmentsAction $syncAttachments,
        private readonly DownloadBotMessageAttachmentsAction $downloadAttachments,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $download = (bool) $this->option('download');

        if ($download && ! $force) {
            $this->warn('--download is ignored without --force.');
            $download = false;
        }

        $messages = $this->candidateMessages();
        $summary = [
            'inspected_messages' => $messages->count(),
            'messages_with_media' => 0,
            'normalized_media_items' => 0,
            'existing_items' => 0,
            'missing_items' => 0,
            'skipped_items' => 0,
        ];
        $rows = [];
        $backfillMessages = [];
        $missingIdentities = [];

        foreach ($messages as $message) {
            $channel = $message->channel;

            if (! $channel instanceof Channel) {
                continue;
            }

            $incomingMessage = $this->normalizeMessage($channel, $message);

            if (! $incomingMessage instanceof IncomingBotMessage || $incomingMessage->media === []) {
                continue;
            }

            $summary['messages_with_media']++;
            $messageHasMissingAttachments = false;

            foreach (array_values($incomingMessage->media) as $mediaItem) {
                if (! is_array($mediaItem)) {
                    $summary['skipped_items']++;

                    continue;
                }

                $summary['normalized_media_items']++;
                $identity = $this->attachmentIdentity($channel, $incomingMessage, $mediaItem);

                if ($identity === null) {
                    $summary['skipped_items']++;

                    continue;
                }

                $exists = MessageAttachment::query()->where($identity)->exists();

                if ($exists) {
                    $summary['existing_items']++;
                } else {
                    $summary['missing_items']++;
                    $messageHasMissingAttachments = true;
                    $missingIdentities[$this->identityKey($identity)] = $identity;
                }

                if (count($rows) < 50) {
                    $rows[] = [
                        $message->id,
                        $channel->id,
                        $identity['provider'],
                        MessageAttachment::mediaKindFromLegacyType(
                            $this->normalizeScalar(data_get($mediaItem, 'media_kind'))
                                ?? $this->normalizeScalar(data_get($mediaItem, 'type')),
                        ),
                        $identity['provider_attachment_key'],
                        $exists ? 'exists' : 'missing',
                    ];
                }
            }

            if ($messageHasMissingAttachments) {
                $backfillMessages[$message->id] = [$message, $channel, $incomingMessage];
            }
        }

        $this->line($force ? 'Bot media attachment backfill started.' : 'Bot media attachment backfill dry-run.');
        $this->table(
            ['Metric', 'Count'],
            array_map(
                static fn (string $metric, int $count): array => [$metric, (string) $count],
                array_keys($summary),
                array_values($summary),
            ),
        );

        if ($rows !== []) {
            $this->table(
                ['Message', 'Channel', 'Provider', 'Kind', 'Attachment key', 'State'],
                $rows,
            );
        }

        if (! $force || $missingIdentities === []) {
            return self::SUCCESS;
        }

        foreach ($backfillMessages as [$message, $channel, $incomingMessage]) {
            $this->syncAttachments->handle($channel, $message, $incomingMessage);
        }

        if ($download) {
            Message::query()
                ->whereKey(array_keys($backfillMessages))
                ->with(['channel', 'attachments'])
                ->get()
                ->each(function (Message $message): void {
                    $this->downloadAttachments->handle($message);
                });
        }

        $createdAttachments = $this->loadAttachmentsByIdentities($missingIdentities);
        $downloadedCount = $createdAttachments
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED)
            ->count();
        $failedCount = $createdAttachments
            ->where('download_status', MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED)
            ->count();

        $this->table(
            ['Result', 'Count'],
            [
                ['backfilled_items', (string) $createdAttachments->count()],
                ['downloaded_items', (string) $downloadedCount],
                ['failed_downloads', (string) $failedCount],
            ],
        );

        return $download && $failedCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, Message>
     */
    private function candidateMessages()
    {
        $limit = min(max((int) $this->option('limit'), 1), 5000);
        $channelId = $this->option('channel');
        $messageIds = array_values(array_filter(
            (array) $this->option('message'),
            static fn (mixed $value): bool => is_scalar($value) && trim((string) $value) !== '',
        ));

        return Message::query()
            ->where('direction', Message::DIRECTION_INBOUND)
            ->whereNotNull('raw_payload')
            ->whereHas('channel', function ($query): void {
                $query
                    ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
                    ->whereIn('platform', [Channel::PLATFORM_TELEGRAM, Channel::PLATFORM_MAX]);
            })
            ->when(filled($channelId), fn ($query) => $query->where('channel_id', (int) $channelId))
            ->when($messageIds !== [], fn ($query) => $query->whereKey(array_map('intval', $messageIds)))
            ->with('channel')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function normalizeMessage(Channel $channel, Message $message): ?IncomingBotMessage
    {
        $rawPayload = $message->raw_payload;

        if (! is_array($rawPayload)) {
            return null;
        }

        return $this->normalizer->normalize($channel, $rawPayload);
    }

    /**
     * @param  array<string, mixed>  $mediaItem
     * @return array{provider: string, channel_id: int, provider_event_key: string, provider_attachment_key: string}|null
     */
    private function attachmentIdentity(
        Channel $channel,
        IncomingBotMessage $incomingMessage,
        array $mediaItem,
    ): ?array {
        $provider = match ($channel->platform) {
            Channel::PLATFORM_TELEGRAM => MessageAttachment::PROVIDER_TELEGRAM_BOT,
            Channel::PLATFORM_MAX => MessageAttachment::PROVIDER_MAX_BOT,
            default => null,
        };
        $providerEventKey = $this->normalizeScalar($incomingMessage->providerEventKey);
        $providerAttachmentKey = $this->normalizeScalar(data_get($mediaItem, 'provider_attachment_key'));

        if ($provider === null || $providerEventKey === null || $providerAttachmentKey === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'channel_id' => $incomingMessage->channelId,
            'provider_event_key' => $providerEventKey,
            'provider_attachment_key' => $providerAttachmentKey,
        ];
    }

    /**
     * @param  array<string, array{provider: string, channel_id: int, provider_event_key: string, provider_attachment_key: string}>  $identities
     * @return Collection<int, MessageAttachment>
     */
    private function loadAttachmentsByIdentities(array $identities)
    {
        return collect($identities)
            ->map(fn (array $identity): ?MessageAttachment => MessageAttachment::query()
                ->where($identity)
                ->first())
            ->filter()
            ->values();
    }

    /**
     * @param  array{provider: string, channel_id: int, provider_event_key: string, provider_attachment_key: string}  $identity
     */
    private function identityKey(array $identity): string
    {
        return implode('|', [
            $identity['provider'],
            $identity['channel_id'],
            $identity['provider_event_key'],
            $identity['provider_attachment_key'],
        ]);
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
