<?php

namespace App\Services\TelegramAccount;

use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Services\Messages\StoreMessageAttachmentLocalFileAction;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;

class CreateTelegramAccountMediaUploadTargetAction
{
    private const S3_UPLOAD_TARGET_TTL_MINUTES = 120;

    private const LOCAL_UPLOAD_TARGET_TTL_MINUTES = 24 * 60;

    public const LOCAL_UPLOAD_CHUNK_BYTES = 8 * 1024 * 1024;

    public const STRATEGY_DIRECT_PUT = 'direct_put';

    public const STRATEGY_MULTIPART = 'laravel_multipart';

    public function __construct(
        private readonly StoreMessageAttachmentLocalFileAction $storeMessageAttachmentLocalFileAction,
    ) {}

    /**
     * @return array{strategy:string,url?:string,headers?:array<string,string>,requires_gateway_auth?:bool,max_chunk_bytes?:int,expires_in_seconds?:int}
     */
    public function handle(Channel $channel, MessageAttachment $attachment): array
    {
        if (
            (int) $attachment->channel_id !== (int) $channel->id
            || $attachment->provider !== MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT
            || $attachment->download_status !== MessageAttachment::DOWNLOAD_STATUS_DOWNLOADING
        ) {
            throw new InvalidArgumentException('Media upload target is not available for this attachment.');
        }

        $claimToken = trim((string) $attachment->media_download_claim_token);

        if ($claimToken === '') {
            throw new InvalidArgumentException('Media upload target requires an active claim token.');
        }

        $disk = MessageAttachment::storageDiskName();
        $driver = (string) config("filesystems.disks.{$disk}.driver", '');
        $path = $this->storeMessageAttachmentLocalFileAction->buildDirectUploadPath($attachment, $claimToken);

        if ($driver === 's3') {
            $target = Storage::disk($disk)->temporaryUploadUrl(
                $path,
                now()->addMinutes(self::S3_UPLOAD_TARGET_TTL_MINUTES),
            );

            return [
                'strategy' => self::STRATEGY_DIRECT_PUT,
                'url' => (string) $target['url'],
                'headers' => $this->normalizeHeaders($target['headers'] ?? []),
                'requires_gateway_auth' => false,
                'expires_in_seconds' => self::S3_UPLOAD_TARGET_TTL_MINUTES * 60,
            ];
        }

        if ($driver === 'local') {
            return [
                'strategy' => self::STRATEGY_DIRECT_PUT,
                'url' => URL::temporarySignedRoute(
                    'internal.telegram-account.media-downloads.upload',
                    now()->addMinutes(self::LOCAL_UPLOAD_TARGET_TTL_MINUTES),
                    [
                        'channel' => $channel,
                        'attachment' => $attachment,
                        'claim_token' => $claimToken,
                    ],
                    absolute: false,
                ),
                'requires_gateway_auth' => true,
                'max_chunk_bytes' => self::LOCAL_UPLOAD_CHUNK_BYTES,
                'expires_in_seconds' => self::LOCAL_UPLOAD_TARGET_TTL_MINUTES * 60,
            ];
        }

        throw new RuntimeException("Message attachment disk [{$disk}] does not support direct media upload.");
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, string>
     */
    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            if (mb_strtolower((string) $name) === 'host') {
                continue;
            }

            $values = is_array($value) ? $value : [$value];
            $headerValue = collect($values)
                ->filter(static fn (mixed $part): bool => is_scalar($part))
                ->map(static fn (mixed $part): string => trim((string) $part))
                ->filter()
                ->implode(', ');

            if ($headerValue !== '') {
                $normalized[(string) $name] = $headerValue;
            }
        }

        return $normalized;
    }
}
