<?php

namespace App\Models;

use App\Services\Messages\RecordInboundMediaStateTransitionAction;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class MessageAttachment extends Model
{
    use HasFactory;

    public const PROVIDER_TELEGRAM_ACCOUNT = 'telegram_account';

    public const PROVIDER_TELEGRAM_BOT = 'telegram_bot';

    public const PROVIDER_MAX_BOT = 'max_bot';

    public const LOCAL_DISK_PRIVATE = 'local';

    public const LOCAL_DISK_MESSAGE_ATTACHMENTS = 'message_attachments';

    public const LOCAL_PATH_PREFIX = 'message-attachments';

    public const MEDIA_KIND_IMAGE = 'image';

    public const MEDIA_KIND_DOCUMENT = 'document';

    public const MEDIA_KIND_VIDEO = 'video';

    public const MEDIA_KIND_VIDEO_NOTE = 'video_note';

    public const MEDIA_KIND_AUDIO = 'audio';

    public const MEDIA_KIND_VOICE = 'voice';

    public const MEDIA_KIND_STICKER = 'sticker';

    public const MEDIA_KIND_ANIMATION = 'animation';

    public const MEDIA_KIND_UNKNOWN = 'unknown';

    public const PREVIEW_KIND_IMAGE = 'image';

    public const PREVIEW_KIND_PDF = 'pdf';

    public const PREVIEW_KIND_VIDEO = 'video';

    public const PREVIEW_KIND_AUDIO = 'audio';

    public const DOWNLOAD_STATUS_METADATA_ONLY = 'metadata_only';

    public const DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND = 'available_on_demand';

    public const DOWNLOAD_STATUS_PENDING_DOWNLOAD = 'pending_download';

    public const DOWNLOAD_STATUS_DOWNLOADING = 'downloading';

    public const DOWNLOAD_STATUS_DOWNLOADED = 'downloaded';

    public const DOWNLOAD_STATUS_DOWNLOAD_FAILED = 'download_failed';

    public const DOWNLOAD_STATUS_DELETED_LOCAL = 'deleted_local';

    public const SEND_STATUS_NOT_APPLICABLE = 'not_applicable';

    public const SEND_STATUS_PENDING_SEND = 'pending_send';

    public const SEND_STATUS_SENDING = 'sending';

    public const SEND_STATUS_SENT = 'sent';

    public const SEND_STATUS_SEND_FAILED = 'send_failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'channel_id',
        'provider',
        'provider_event_key',
        'provider_attachment_key',
        'outbound_attachment_key',
        'media_kind',
        'mime_type',
        'extension',
        'original_filename',
        'file_size_bytes',
        'provider_file_id',
        'provider_file_unique_id',
        'provider_file_reference',
        'provider_metadata',
        'download_status',
        'manual_download_requested_at',
        'manual_download_requested_by_user_id',
        'media_download_claim_token',
        'media_download_upload_size_bytes',
        'media_download_next_retry_at',
        'media_download_max_bytes',
        'media_download_generation',
        'media_download_attempts',
        'media_download_lease_sequence',
        'media_download_trigger',
        'media_download_claimed_at',
        'media_download_heartbeat_at',
        'media_download_attempt_deadline_at',
        'send_status',
        'local_disk',
        'local_path',
        'safe_error_code',
        'safe_error_message',
        'raw_payload_excerpt',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'file_size_bytes' => 'integer',
        'provider_metadata' => 'array',
        'raw_payload_excerpt' => 'array',
        'sort_order' => 'integer',
        'manual_download_requested_at' => 'datetime',
        'manual_download_requested_by_user_id' => 'integer',
        'media_download_upload_size_bytes' => 'integer',
        'media_download_next_retry_at' => 'datetime',
        'media_download_max_bytes' => 'integer',
        'media_download_generation' => 'integer',
        'media_download_attempts' => 'integer',
        'media_download_lease_sequence' => 'integer',
        'media_download_claimed_at' => 'datetime',
        'media_download_heartbeat_at' => 'datetime',
        'media_download_attempt_deadline_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::created(static function (MessageAttachment $attachment): void {
            app(RecordInboundMediaStateTransitionAction::class)->handle(
                $attachment,
                null,
                null,
            );
        });

        static::updated(static function (MessageAttachment $attachment): void {
            if (! $attachment->wasChanged(['download_status', 'media_download_generation'])) {
                return;
            }

            app(RecordInboundMediaStateTransitionAction::class)->handle(
                $attachment,
                is_string($attachment->getRawOriginal('download_status'))
                    ? $attachment->getRawOriginal('download_status')
                    : null,
                is_numeric($attachment->getRawOriginal('media_download_generation'))
                    ? (int) $attachment->getRawOriginal('media_download_generation')
                    : null,
            );
        });
    }

    public function mediaDownloadLedgerAttemptNumber(): int
    {
        return max(
            1,
            (int) $this->media_download_lease_sequence,
            (int) $this->media_download_attempts,
        );
    }

    /** @return HasMany<MediaDownloadStateTransition, $this> */
    public function stateTransitions(): HasMany
    {
        return $this->hasMany(MediaDownloadStateTransition::class, 'message_attachment_id');
    }

    public static function normalizeMediaKind(mixed $value): string
    {
        if (! is_string($value)) {
            return self::MEDIA_KIND_UNKNOWN;
        }

        $normalized = trim($value);

        return in_array($normalized, self::mediaKinds(), true)
            ? $normalized
            : self::MEDIA_KIND_UNKNOWN;
    }

    public static function mediaKindFromLegacyType(mixed $value): string
    {
        if (! is_string($value)) {
            return self::MEDIA_KIND_UNKNOWN;
        }

        return match (trim($value)) {
            'photo' => self::MEDIA_KIND_IMAGE,
            self::MEDIA_KIND_IMAGE,
            self::MEDIA_KIND_DOCUMENT,
            self::MEDIA_KIND_VIDEO,
            self::MEDIA_KIND_VIDEO_NOTE,
            self::MEDIA_KIND_AUDIO,
            self::MEDIA_KIND_VOICE,
            self::MEDIA_KIND_STICKER,
            self::MEDIA_KIND_ANIMATION => trim($value),
            default => self::MEDIA_KIND_UNKNOWN,
        };
    }

    public static function normalizeDownloadStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return in_array($normalized, self::downloadStatuses(), true)
            ? $normalized
            : null;
    }

    public static function downloadStatusFromLegacyStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return match (trim($value)) {
            Message::MEDIA_DOWNLOAD_STATUS_PENDING => self::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADING => self::DOWNLOAD_STATUS_DOWNLOADING,
            Message::MEDIA_DOWNLOAD_STATUS_DOWNLOADED => self::DOWNLOAD_STATUS_DOWNLOADED,
            Message::MEDIA_DOWNLOAD_STATUS_FAILED => self::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            default => self::normalizeDownloadStatus($value),
        };
    }

    public static function normalizeSendStatus(mixed $value): string
    {
        if (! is_string($value)) {
            return self::SEND_STATUS_NOT_APPLICABLE;
        }

        $normalized = trim($value);

        return in_array($normalized, self::sendStatuses(), true)
            ? $normalized
            : self::SEND_STATUS_NOT_APPLICABLE;
    }

    public static function sanitizeExtension(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }

        $extension = mb_strtolower(ltrim(trim($value), '.'));

        return preg_match('/^[a-z0-9]{1,16}$/', $extension) === 1
            ? $extension
            : '';
    }

    public static function sanitizeDisplayFilename(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $filename = trim($value);

        if ($filename === '') {
            return null;
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($filename, \Normalizer::FORM_C);

            if (is_string($normalized)) {
                $filename = $normalized;
            }
        }

        $filename = str_replace('\\', '/', $filename);
        $lastSeparator = strrpos($filename, '/');

        if ($lastSeparator !== false) {
            $filename = substr($filename, $lastSeparator + 1);
        }

        $filename = preg_replace('/[\t\n\r\f\v]+/u', ' ', $filename) ?? '';
        $filename = preg_replace(
            '/[\p{Cc}\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]+/u',
            '',
            $filename,
        ) ?? '';
        $filename = trim($filename, " \t\n\r\0\x0B.");

        return $filename !== '' ? mb_substr($filename, 0, 180) : null;
    }

    protected function originalFilename(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => self::sanitizeDisplayFilename($value),
            set: fn (mixed $value): ?string => self::sanitizeDisplayFilename($value),
        );
    }

    public static function isSafeLocalPath(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $path = trim($value);

        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || ! str_starts_with($path, self::LOCAL_PATH_PREFIX.'/')
        ) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    public static function storageDiskName(): string
    {
        $disk = config('filesystems.message_attachments_disk', self::LOCAL_DISK_PRIVATE);

        if (! is_string($disk) || trim($disk) === '') {
            return self::LOCAL_DISK_PRIVATE;
        }

        $disk = trim($disk);
        $configuredDisks = config('filesystems.disks', []);

        if (! is_array($configuredDisks) || ! array_key_exists($disk, $configuredDisks)) {
            throw new RuntimeException(sprintf('Message attachments storage disk [%s] is not configured.', $disk));
        }

        return $disk;
    }

    /**
     * @return list<string>
     */
    public static function readableStorageDiskNames(): array
    {
        return array_values(array_unique(array_filter([
            self::LOCAL_DISK_PRIVATE,
            self::storageDiskName(),
        ], fn ($disk): bool => is_string($disk) && $disk !== '')));
    }

    public function isLocallyDownloadable(): bool
    {
        return $this->download_status === self::DOWNLOAD_STATUS_DOWNLOADED
            && in_array($this->local_disk, self::readableStorageDiskNames(), true)
            && self::isSafeLocalPath($this->local_path);
    }

    public function isInlinePreviewable(): bool
    {
        return $this->previewKind() !== null;
    }

    public function previewKind(): ?string
    {
        if (! $this->isLocallyDownloadable()) {
            return null;
        }

        $mimeType = $this->previewMimeType();

        if (
            in_array($this->media_kind, [self::MEDIA_KIND_IMAGE, self::MEDIA_KIND_STICKER], true)
            && self::isSupportedImagePreviewMimeType($mimeType)
        ) {
            return self::PREVIEW_KIND_IMAGE;
        }

        if (in_array($this->media_kind, [self::MEDIA_KIND_VIDEO, self::MEDIA_KIND_VIDEO_NOTE, self::MEDIA_KIND_ANIMATION], true) && self::isSupportedVideoPreviewMimeType($mimeType)) {
            return self::PREVIEW_KIND_VIDEO;
        }

        if (in_array($this->media_kind, [self::MEDIA_KIND_AUDIO, self::MEDIA_KIND_VOICE], true) && self::isSupportedAudioPreviewMimeType($mimeType)) {
            return self::PREVIEW_KIND_AUDIO;
        }

        return null;
    }

    public function previewMimeType(): ?string
    {
        $mimeType = $this->downloadMimeType();

        if (self::isSupportedPreviewMimeType($mimeType)) {
            return $mimeType;
        }

        $extensionMimeType = in_array($this->media_kind, [self::MEDIA_KIND_AUDIO, self::MEDIA_KIND_VOICE], true)
            ? self::previewAudioMimeTypeFromExtension($this->extension)
            : self::previewMimeTypeFromExtension($this->extension);

        if ($extensionMimeType !== null) {
            return $extensionMimeType;
        }

        $localPathExtensionMimeType = $this->previewMimeTypeFromLocalPathExtension();

        if ($localPathExtensionMimeType !== null) {
            return $localPathExtensionMimeType;
        }

        if (in_array($this->media_kind, [self::MEDIA_KIND_IMAGE, self::MEDIA_KIND_STICKER], true) && $this->isLocallyDownloadable()) {
            return $this->previewMimeTypeFromLocalFile();
        }

        return null;
    }

    public static function previewMimeTypeFromContents(string $contents): ?string
    {
        if (str_starts_with($contents, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }

        if (str_starts_with($contents, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }

        if (str_starts_with($contents, 'GIF87a') || str_starts_with($contents, 'GIF89a')) {
            return 'image/gif';
        }

        if (strlen($contents) >= 12 && substr($contents, 0, 4) === 'RIFF' && substr($contents, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        return null;
    }

    public static function previewExtensionForMimeType(mixed $mimeType): ?string
    {
        return match (is_string($mimeType) ? mb_strtolower(trim($mimeType)) : null) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/ogg' => 'ogv',
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'audio/aac' => 'aac',
            'audio/wav', 'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            default => null,
        };
    }

    public static function isGenericMimeType(mixed $mimeType): bool
    {
        if (! is_string($mimeType)) {
            return true;
        }

        return trim(mb_strtolower($mimeType)) === ''
            || trim(mb_strtolower($mimeType)) === 'application/octet-stream';
    }

    private static function isSupportedPreviewMimeType(string $mimeType): bool
    {
        return self::isSupportedImagePreviewMimeType($mimeType)
            || self::isSupportedVideoPreviewMimeType($mimeType)
            || self::isSupportedAudioPreviewMimeType($mimeType);
    }

    private static function isSupportedImagePreviewMimeType(?string $mimeType): bool
    {
        return in_array($mimeType, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ], true);
    }

    private static function isSupportedVideoPreviewMimeType(?string $mimeType): bool
    {
        return in_array($mimeType, [
            'video/mp4',
            'video/webm',
            'video/ogg',
        ], true);
    }

    private static function isSupportedAudioPreviewMimeType(?string $mimeType): bool
    {
        return in_array($mimeType, [
            'audio/mpeg',
            'audio/mp4',
            'audio/aac',
            'audio/wav',
            'audio/x-wav',
            'audio/ogg',
            'audio/webm',
        ], true);
    }

    private static function previewAudioMimeTypeFromExtension(mixed $extension): ?string
    {
        return match (self::sanitizeExtension($extension)) {
            'mp3' => 'audio/mpeg',
            'm4a' => 'audio/mp4',
            'aac' => 'audio/aac',
            'wav' => 'audio/wav',
            'oga', 'ogg', 'opus' => 'audio/ogg',
            'weba', 'webm' => 'audio/webm',
            default => null,
        };
    }

    private static function previewMimeTypeFromExtension(mixed $extension): ?string
    {
        return match (self::sanitizeExtension($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'mp4', 'm4v' => 'video/mp4',
            'webm' => 'video/webm',
            'ogv', 'ogg' => 'video/ogg',
            default => null,
        };
    }

    private function previewMimeTypeFromLocalPathExtension(): ?string
    {
        if (! $this->isLocallyDownloadable()) {
            return null;
        }

        $extension = self::sanitizeExtension(pathinfo((string) $this->local_path, PATHINFO_EXTENSION));

        if ($extension === '') {
            return null;
        }

        return in_array($this->media_kind, [self::MEDIA_KIND_AUDIO, self::MEDIA_KIND_VOICE], true)
            ? self::previewAudioMimeTypeFromExtension($extension)
            : self::previewMimeTypeFromExtension($extension);
    }

    private function previewMimeTypeFromLocalFile(): ?string
    {
        $disk = (string) $this->local_disk;
        $path = (string) $this->local_path;

        if (! self::isSafeLocalPath($path)) {
            return null;
        }

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (\Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $header = fread($stream, 64);
        } finally {
            fclose($stream);
        }

        return is_string($header) ? self::previewMimeTypeFromContents($header) : null;
    }

    public function downloadFilename(): string
    {
        $filename = self::sanitizeDisplayFilename($this->original_filename) ?? '';

        if ($filename !== '') {
            return $filename;
        }

        $extension = self::sanitizeExtension($this->extension);

        return 'attachment-'.$this->getKey().($extension !== '' ? '.'.$extension : '');
    }

    public function downloadMimeType(): string
    {
        if (! is_string($this->mime_type)) {
            return 'application/octet-stream';
        }

        $mimeType = mb_strtolower(trim($this->mime_type));

        return preg_match('/\A[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\/[a-z0-9][a-z0-9!#$&^_.+-]{0,126}\z/', $mimeType) === 1
            ? $mimeType
            : 'application/octet-stream';
    }

    /**
     * @return list<string>
     */
    public static function mediaKinds(): array
    {
        return [
            self::MEDIA_KIND_IMAGE,
            self::MEDIA_KIND_DOCUMENT,
            self::MEDIA_KIND_VIDEO,
            self::MEDIA_KIND_VIDEO_NOTE,
            self::MEDIA_KIND_AUDIO,
            self::MEDIA_KIND_VOICE,
            self::MEDIA_KIND_STICKER,
            self::MEDIA_KIND_ANIMATION,
            self::MEDIA_KIND_UNKNOWN,
        ];
    }

    /**
     * @return list<string>
     */
    public static function downloadStatuses(): array
    {
        return [
            self::DOWNLOAD_STATUS_METADATA_ONLY,
            self::DOWNLOAD_STATUS_AVAILABLE_ON_DEMAND,
            self::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            self::DOWNLOAD_STATUS_DOWNLOADING,
            self::DOWNLOAD_STATUS_DOWNLOADED,
            self::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            self::DOWNLOAD_STATUS_DELETED_LOCAL,
        ];
    }

    /**
     * @return list<string>
     */
    public static function sendStatuses(): array
    {
        return [
            self::SEND_STATUS_NOT_APPLICABLE,
            self::SEND_STATUS_PENDING_SEND,
            self::SEND_STATUS_SENDING,
            self::SEND_STATUS_SENT,
            self::SEND_STATUS_SEND_FAILED,
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function manualDownloadRequestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manual_download_requested_by_user_id');
    }
}
