<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Throwable;

class MessageAttachmentPosterController extends Controller
{
    public function __invoke(string $attachment): RedirectResponse|Response
    {
        if (! Auth::check()) {
            return redirect('/admin/login');
        }

        $attachment = MessageAttachment::query()->findOrFail($attachment);

        $attachment->loadMissing('message.dialog');

        $dialog = $attachment->message?->dialog;

        if ($dialog === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        Gate::authorize('view', $dialog);

        if (! $this->canPreviewPoster($attachment)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $url = $this->resolvePosterUrl($attachment);

        if ($url === null) {
            abort(Response::HTTP_NOT_FOUND);
        }

        try {
            $response = Http::withoutRedirecting()
                ->timeout(12)
                ->get($url)
                ->throw();
        } catch (Throwable) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $contentType = $this->normalizeImageContentType($response->header('Content-Type'));
        $contents = $response->body();

        if ($contentType === null || $contents === '' || strlen($contents) > 5 * 1024 * 1024) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'private, max-age=300',
            'Content-Disposition' => 'inline; filename="video-poster"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function canPreviewPoster(MessageAttachment $attachment): bool
    {
        return $attachment->provider === MessageAttachment::PROVIDER_MAX_BOT
            && $attachment->media_kind === MessageAttachment::MEDIA_KIND_VIDEO
            && $attachment->isInlinePreviewable();
    }

    private function resolvePosterUrl(MessageAttachment $attachment): ?string
    {
        $reference = $this->normalizeScalar($attachment->provider_file_reference)
            ?? $this->normalizeScalar($attachment->provider_attachment_key);

        if ($reference === null) {
            return null;
        }

        foreach ($this->maxAttachmentCandidates($attachment) as $index => $candidate) {
            if ($this->resolveMaxAttachmentReference($candidate, $index) !== $reference) {
                continue;
            }

            $url = $this->normalizeScalar(data_get($candidate, 'thumbnail.url'))
                ?? $this->normalizeScalar(data_get($candidate, 'payload.thumbnail.url'));

            return $url === null ? null : $this->validateTrustedMaxMediaUrl($url);
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function maxAttachmentCandidates(MessageAttachment $attachment): array
    {
        $payload = is_array($attachment->message?->raw_payload) ? $attachment->message->raw_payload : [];
        $candidates = [];

        // link.message.* намеренно без forward-гейта — lookup-стог по референсу,
        // как в DownloadBotMessageAttachmentsAction (грандфазеринг до-f207b891 строк).
        // Набор источников выровнен с даунлоадером.
        foreach ([
            data_get($payload, 'message.body.attachments'),
            data_get($payload, 'message.attachments'),
            data_get($payload, 'message.link.message.body.attachments'),
            data_get($payload, 'message.link.message.attachments'),
            data_get($payload, 'body.attachments'),
            data_get($payload, 'attachments'),
        ] as $attachments) {
            if (! is_array($attachments)) {
                continue;
            }

            foreach ($attachments as $candidate) {
                if (is_array($candidate)) {
                    $candidates[] = $candidate;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, mixed>  $attachment
     */
    private function resolveMaxAttachmentReference(array $attachment, int $index): ?string
    {
        $tokenReference = $this->hashSensitiveReference(
            data_get($attachment, 'payload.token')
                ?? data_get($attachment, 'token'),
            'token',
        );

        if ($tokenReference !== null) {
            return $tokenReference;
        }

        return $this->normalizeScalar(
            data_get($attachment, 'payload.file_id')
                ?? data_get($attachment, 'file_id')
                ?? data_get($attachment, 'payload.id')
                ?? data_get($attachment, 'id')
        ) ?? 'index:'.$index;
    }

    private function hashSensitiveReference(mixed $value, string $prefix): ?string
    {
        $normalized = $this->normalizeScalar($value);

        return $normalized === null ? null : $prefix.':'.sha1($normalized);
    }

    private function validateTrustedMaxMediaUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            return null;
        }

        if (array_key_exists('port', $parts) || array_key_exists('user', $parts) || array_key_exists('pass', $parts)) {
            return null;
        }

        $trustedHosts = array_values(array_filter(
            (array) config('bots.max.trusted_media_hosts', config('bots.max.trusted_avatar_hosts', ['max.ru'])),
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));

        foreach ($trustedHosts as $trustedHost) {
            $normalizedTrustedHost = strtolower(trim($trustedHost));

            if ($host === $normalizedTrustedHost || str_ends_with($host, '.'.$normalizedTrustedHost)) {
                return $url;
            }
        }

        return null;
    }

    private function normalizeImageContentType(?string $contentType): ?string
    {
        if ($contentType === null) {
            return null;
        }

        $normalized = strtolower(trim(explode(';', $contentType, 2)[0]));

        return in_array($normalized, [
            'image/jpeg',
            'image/png',
            'image/webp',
            'image/gif',
        ], true) ? $normalized : null;
    }

    private function normalizeScalar(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
