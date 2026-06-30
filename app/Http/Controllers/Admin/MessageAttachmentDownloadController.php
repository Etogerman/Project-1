<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MessageAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MessageAttachmentDownloadController extends Controller
{
    public function __invoke(string $attachment): RedirectResponse|StreamedResponse
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

        if (! $attachment->isLocallyDownloadable()) {
            abort(Response::HTTP_NOT_FOUND);
        }

        $disk = (string) $attachment->local_disk;
        $path = (string) $attachment->local_path;

        if (! Storage::disk($disk)->exists($path)) {
            abort(Response::HTTP_NOT_FOUND);
        }

        return Storage::disk($disk)->download(
            $path,
            $attachment->downloadFilename(),
            [
                'Content-Type' => $attachment->downloadMimeType(),
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
