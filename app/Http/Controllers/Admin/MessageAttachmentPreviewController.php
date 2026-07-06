<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentPreviewController extends Controller
{
    public function __invoke(string $attachment): RedirectResponse|StreamedResponse|BinaryFileResponse
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

        if (! $attachment->isInlinePreviewable()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = (string) $attachment->local_disk;
        $path = (string) $attachment->local_path;
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $headers = [
            'Content-Type' => $attachment->previewMimeType() ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ];

        $localPath = $this->resolveLocalPreviewPath($storage, $path);

        if ($localPath !== null) {
            return response()->download(
                $localPath,
                $attachment->downloadFilename(),
                $headers,
                'inline',
            );
        }

        return $storage->response(
            $path,
            $attachment->downloadFilename(),
            $headers,
        );
    }

    private function resolveLocalPreviewPath(FilesystemAdapter $storage, string $path): ?string
    {
        try {
            $localPath = $storage->path($path);
        } catch (\Throwable) {
            return null;
        }

        return is_file($localPath) ? $localPath : null;
    }
}
