<?php

namespace App\Http\Controllers;

use App\Data\TelegramAccount\NormalizedExternalOutgoingMessageEvent;
use App\Models\Channel;
use App\Models\MessageAttachment;
use App\Models\TelegramAccountOutgoingMessage;
use App\Services\TelegramAccount\ClaimTelegramAccountMediaDownloadAction;
use App\Services\TelegramAccount\ClaimTelegramAccountOutgoingMessageAction;
use App\Services\TelegramAccount\CreateTelegramAccountMediaUploadTargetAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountExternalOutgoingMessageEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountInboundMessageEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\NormalizeTelegramAccountRuntimeStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountExternalOutgoingMessageEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountInboundEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDirectUploadAction;
use App\Services\TelegramAccount\StoreTelegramAccountMediaDownloadResultAction;
use App\Services\TelegramAccount\StoreTelegramAccountOutgoingMessageResultAction;
use App\Services\TelegramAccount\StoreTelegramAccountPeerSyncStateEventAction;
use App\Services\TelegramAccount\StoreTelegramAccountRuntimeStateEventAction;
use App\Services\TelegramAccount\TelegramAccountMediaDownloadPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class TelegramAccountGatewayController extends Controller
{
    public const MEDIA_CLAIM_TOKEN_CAPABILITY_HEADER = 'X-AB-Media-Claim-Token';

    public function config(Request $request, Channel $channel): JsonResponse
    {
        $this->authorizeGatewayRequest($request, $channel);

        return response()->json([
            'ok' => true,
            'channel_id' => $channel->id,
            'sync_external_outgoing_enabled' => (bool) $channel->sync_external_outgoing_enabled,
            'external_outgoing_backfill_days' => max(1, (int) config('bots.telegram_account.external_outgoing_backfill_days', 7)),
            'external_outgoing_backfill_known_dialogs_only' => (bool) config('bots.telegram_account.external_outgoing_backfill_known_dialogs_only', true),
            'external_outgoing_echo_deferral_seconds' => max(0, (int) config('bots.telegram_account.external_outgoing_echo_deferral_seconds', 15)),
            'external_outgoing_echo_retry_interval_seconds' => max(1, (int) config('bots.telegram_account.external_outgoing_echo_retry_interval_seconds', 1)),
            'external_outgoing_echo_near_time_window_seconds' => max(1, (int) config('bots.telegram_account.external_outgoing_echo_near_time_window_seconds', 120)),
            'config_version' => $channel->updated_at?->getTimestamp() ?? 0,
        ]);
    }

    public function inboundMessage(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountInboundMessageEventAction $normalizeTelegramAccountInboundMessageEventAction,
        StoreTelegramAccountInboundEventAction $storeTelegramAccountInboundEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountInboundMessageEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $storedResult = $storeTelegramAccountInboundEventAction->handle($channel, $event);

        if ($storedResult === null) {
            return response()->json([
                'ok' => true,
                'stored' => false,
                'skipped' => true,
            ]);
        }

        return response()->json([
            'ok' => true,
            'stored' => true,
            'skipped' => false,
            'message_id' => $storedResult->message->id,
            'dialog_id' => $storedResult->message->dialog_id,
        ]);
    }

    public function externalOutgoingMessage(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountExternalOutgoingMessageEventAction $normalizeTelegramAccountExternalOutgoingMessageEventAction,
        StoreTelegramAccountExternalOutgoingMessageEventAction $storeTelegramAccountExternalOutgoingMessageEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        try {
            $event = $normalizeTelegramAccountExternalOutgoingMessageEventAction->handle(
                $channel,
                $request->json()->all(),
            );
        } catch (ValidationException $exception) {
            $payload = $request->json()->all();

            Log::warning('telegram_account_gateway.external_outgoing_invalid_payload', [
                'channel_id' => $channel->id,
                'gateway_event_id' => data_get($payload, 'gateway_event_id'),
                'peer_key' => data_get($payload, 'peer_key'),
                'message_key' => data_get($payload, 'message_key'),
                'errors' => $exception->errors(),
            ]);

            return response()->json([
                'ok' => true,
                'stored' => false,
                'skipped' => true,
                'skip_reason' => NormalizedExternalOutgoingMessageEvent::SKIP_INVALID_PAYLOAD,
            ]);
        }

        $storedResult = $storeTelegramAccountExternalOutgoingMessageEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => $storedResult->stored,
            'skipped' => $storedResult->skipped,
            'skip_reason' => $storedResult->skipReason,
            'message_id' => $storedResult->message?->id,
            'dialog_id' => $storedResult->message?->dialog_id,
        ]);
    }

    public function runtimeState(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountRuntimeStateEventAction $normalizeTelegramAccountRuntimeStateEventAction,
        StoreTelegramAccountRuntimeStateEventAction $storeTelegramAccountRuntimeStateEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountRuntimeStateEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $runtimeState = $storeTelegramAccountRuntimeStateEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => true,
            'runtime_state_id' => $runtimeState->id,
        ]);
    }

    public function peerSyncState(
        Request $request,
        Channel $channel,
        NormalizeTelegramAccountPeerSyncStateEventAction $normalizeTelegramAccountPeerSyncStateEventAction,
        StoreTelegramAccountPeerSyncStateEventAction $storeTelegramAccountPeerSyncStateEventAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $event = $normalizeTelegramAccountPeerSyncStateEventAction->handle(
            $channel,
            $request->json()->all(),
        );
        $peerSyncState = $storeTelegramAccountPeerSyncStateEventAction->handle($channel, $event);

        return response()->json([
            'ok' => true,
            'stored' => true,
            'peer_sync_state_id' => $peerSyncState->id,
        ]);
    }

    public function claimOutgoingMessage(
        Request $request,
        Channel $channel,
        ClaimTelegramAccountOutgoingMessageAction $claimTelegramAccountOutgoingMessageAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $outgoing = $claimTelegramAccountOutgoingMessageAction->handle($channel);

        if (! $outgoing instanceof TelegramAccountOutgoingMessage) {
            return response()->json([
                'ok' => true,
                'has_message' => false,
            ]);
        }

        return response()->json([
            'ok' => true,
            'has_message' => true,
            'outgoing_message' => [
                'id' => $outgoing->id,
                'channel_id' => $outgoing->channel_id,
                'dialog_id' => $outgoing->dialog_id,
                'message_id' => $outgoing->message_id,
                'external_chat_id' => $outgoing->external_chat_id,
                'text' => $outgoing->text,
                'text_format' => $outgoing->text_format,
                'dedupe_key' => $outgoing->dedupe_key,
                'attempts' => $outgoing->attempts,
            ],
        ]);
    }

    public function outgoingMessageResult(
        Request $request,
        Channel $channel,
        TelegramAccountOutgoingMessage $outgoingMessage,
        StoreTelegramAccountOutgoingMessageResultAction $storeTelegramAccountOutgoingMessageResultAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);
        abort_unless((int) $outgoingMessage->channel_id === (int) $channel->id, 404);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                TelegramAccountOutgoingMessage::STATUS_SENT,
                TelegramAccountOutgoingMessage::STATUS_FAILED,
            ])],
            'external_message_id' => ['required_if:status,'.TelegramAccountOutgoingMessage::STATUS_SENT, 'nullable', 'string'],
            'error_message' => ['nullable', 'string'],
            'raw_payload' => ['nullable', 'array'],
        ]);

        $stored = $storeTelegramAccountOutgoingMessageResultAction->handle(
            $channel,
            $outgoingMessage,
            $validated,
        );

        return response()->json([
            'ok' => true,
            'stored' => true,
            'outgoing_message_id' => $stored->id,
            'status' => $stored->status,
            'message_id' => $stored->message_id,
        ]);
    }

    public function claimMediaDownload(
        Request $request,
        Channel $channel,
        ClaimTelegramAccountMediaDownloadAction $claimTelegramAccountMediaDownloadAction,
        CreateTelegramAccountMediaUploadTargetAction $createTelegramAccountMediaUploadTargetAction,
        TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        $supportsClaimToken = $request->header(self::MEDIA_CLAIM_TOKEN_CAPABILITY_HEADER) === '1';
        $attachment = $claimTelegramAccountMediaDownloadAction->handle($channel, $supportsClaimToken);

        if (! $attachment instanceof MessageAttachment) {
            return response()->json([
                'ok' => true,
                'has_download' => false,
            ]);
        }

        $manualDownload = $attachment->manual_download_requested_at !== null;
        $upload = null;

        if ($supportsClaimToken) {
            $claimToken = (string) $attachment->media_download_claim_token;

            try {
                $upload = $createTelegramAccountMediaUploadTargetAction->handle($channel, $attachment);
            } catch (Throwable $exception) {
                $claimTelegramAccountMediaDownloadAction->releaseAfterUploadTargetFailure(
                    $channel,
                    $attachment,
                    $claimToken,
                );

                Log::warning('telegram_account_media.upload_target_creation_failed', [
                    'channel_id' => $channel->id,
                    'attachment_id' => $attachment->id,
                    'error_type' => $exception::class,
                ]);

                throw new RuntimeException('Telegram Account media upload target is unavailable.');
            }
        }

        $mediaDownload = [
            'attachment_id' => $attachment->id,
            'channel_id' => $attachment->channel_id,
            'message_id' => $attachment->message_id,
            'provider' => $attachment->provider,
            'provider_event_key' => $attachment->provider_event_key,
            'provider_attachment_key' => $attachment->provider_attachment_key,
            'provider_file_id' => $attachment->provider_file_id ?: $attachment->provider_file_reference,
            'provider_file_reference' => $attachment->provider_file_reference,
            'media_kind' => $attachment->media_kind,
            'mime_type' => $attachment->mime_type,
            'original_filename' => $attachment->original_filename,
            'file_size_bytes' => $attachment->file_size_bytes,
            'attempt' => 1,
            'download_mode' => $manualDownload ? 'manual' : 'automatic',
            'max_bytes' => $manualDownload
                ? null
                : ($attachment->media_download_max_bytes
                    ?? $mediaDownloadPolicy->automaticMaxBytes($channel)),
        ];

        if ($supportsClaimToken) {
            $mediaDownload['claim_token'] = $attachment->media_download_claim_token;
            $mediaDownload['upload'] = $upload;
        }

        return response()->json([
            'ok' => true,
            'has_download' => true,
            'media_download' => $mediaDownload,
        ]);
    }

    public function uploadMediaDownload(
        Request $request,
        Channel $channel,
        MessageAttachment $attachment,
        StoreTelegramAccountMediaDirectUploadAction $storeTelegramAccountMediaDirectUploadAction,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);
        abort_unless($request->hasValidSignature(absolute: false), 403);

        $claimToken = trim((string) $request->query('claim_token', ''));

        abort_if($claimToken === '', 422);

        $stream = $request->getContent(asResource: true);

        abort_unless(is_resource($stream), 422);

        try {
            $fileSizeBytes = $storeTelegramAccountMediaDirectUploadAction->handle(
                $channel,
                $attachment,
                $claimToken,
                $stream,
                $request->header('Content-Range'),
            );
        } catch (InvalidArgumentException $exception) {
            abort(409, $exception->getMessage());
        } finally {
            fclose($stream);
        }

        return response()->json([
            'ok' => true,
            'stored' => true,
            'attachment_id' => $attachment->id,
            'file_size_bytes' => $fileSizeBytes,
        ]);
    }

    public function mediaDownloadResult(
        Request $request,
        Channel $channel,
        MessageAttachment $attachment,
        StoreTelegramAccountMediaDownloadResultAction $storeTelegramAccountMediaDownloadResultAction,
        TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
    ): JsonResponse {
        $this->authorizeGatewayRequest($request, $channel);

        abort_unless(
            (int) $attachment->channel_id === (int) $channel->id
                && $attachment->provider === MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            404,
        );

        $directUpload = $request->input('upload_strategy') === CreateTelegramAccountMediaUploadTargetAction::STRATEGY_DIRECT_PUT;
        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in([
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                'failed',
                MessageAttachment::DOWNLOAD_STATUS_DOWNLOAD_FAILED,
            ])],
            'upload_strategy' => ['nullable', 'string', Rule::in([
                CreateTelegramAccountMediaUploadTargetAction::STRATEGY_DIRECT_PUT,
                CreateTelegramAccountMediaUploadTargetAction::STRATEGY_MULTIPART,
            ])],
            'claim_token' => [
                Rule::requiredIf($directUpload || filled($attachment->media_download_claim_token)),
                'nullable',
                'string',
                'max:64',
            ],
            'file' => [
                Rule::requiredIf(
                    $request->input('status') === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED
                        && ! $directUpload,
                ),
                'file',
            ],
            'mime_type' => ['nullable', 'string'],
            'original_filename' => ['nullable', 'string'],
            'file_size_bytes' => [
                Rule::requiredIf(
                    $directUpload
                        && $request->input('status') === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED,
                ),
                'nullable',
                'integer',
                'min:0',
            ],
            'provider_file_id' => ['nullable', 'string'],
            'error_code' => ['required_unless:status,'.MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED, 'nullable', 'string'],
            'error_message' => ['nullable', 'string'],
            'retryable' => ['nullable', 'boolean'],
            'raw_payload' => ['nullable', 'array'],
        ]);

        $status = (string) $validated['status'];
        $claimToken = isset($validated['claim_token'])
            ? (string) $validated['claim_token']
            : null;

        $alreadyHandled = $storeTelegramAccountMediaDownloadResultAction->acknowledgeHandledResult(
            $channel,
            $attachment,
        );

        if ($alreadyHandled instanceof MessageAttachment) {
            return response()->json([
                'ok' => true,
                'stored' => true,
                'attachment_id' => $alreadyHandled->id,
                'download_status' => $alreadyHandled->download_status,
            ]);
        }

        try {
            if ($status === MessageAttachment::DOWNLOAD_STATUS_DOWNLOADED) {
                if ($directUpload) {
                    $directClaimToken = (string) $claimToken;
                    $fileSizeBytes = $storeTelegramAccountMediaDownloadResultAction->directUploadSize(
                        $channel,
                        $attachment,
                        $directClaimToken,
                    );

                    if ((int) $validated['file_size_bytes'] !== $fileSizeBytes) {
                        throw new InvalidArgumentException('Direct media upload size does not match reported file size.');
                    }

                    $manualDownload = $attachment->manual_download_requested_at !== null;

                    if ($this->exceedsClaimedAutomaticLimit(
                        $channel,
                        $attachment,
                        $fileSizeBytes,
                        $manualDownload,
                        $mediaDownloadPolicy,
                    )) {
                        $stored = $storeTelegramAccountMediaDownloadResultAction->markAvailableOnDemand(
                            $channel,
                            $attachment,
                            $directClaimToken,
                        );
                    } else {
                        $stored = $storeTelegramAccountMediaDownloadResultAction->markDownloadedFromDirectUpload(
                            $channel,
                            $attachment,
                            $directClaimToken,
                            [
                                'mime_type' => $validated['mime_type'] ?? null,
                                'original_filename' => $validated['original_filename'] ?? null,
                                'file_size_bytes' => $validated['file_size_bytes'],
                                'provider_file_id' => $validated['provider_file_id'] ?? null,
                            ],
                        );
                    }

                    return response()->json([
                        'ok' => true,
                        'stored' => true,
                        'attachment_id' => $stored->id,
                        'download_status' => $stored->download_status,
                    ]);
                }

                $file = $request->file('file');

                abort_unless($file instanceof UploadedFile, 422);

                $fileSizeBytes = $file->getSize();
                $manualDownload = $attachment->manual_download_requested_at !== null;

                if ($this->exceedsClaimedAutomaticLimit(
                    $channel,
                    $attachment,
                    $fileSizeBytes,
                    $manualDownload,
                    $mediaDownloadPolicy,
                )) {
                    $stored = $storeTelegramAccountMediaDownloadResultAction->markAvailableOnDemand(
                        $channel,
                        $attachment,
                        $claimToken,
                    );

                    return response()->json([
                        'ok' => true,
                        'stored' => true,
                        'attachment_id' => $stored->id,
                        'download_status' => $stored->download_status,
                    ]);
                }

                $stream = fopen($file->getRealPath(), 'rb');

                abort_if($stream === false, 422);

                try {
                    $stored = $storeTelegramAccountMediaDownloadResultAction->markDownloadedFromStream(
                        $channel,
                        $attachment,
                        $stream,
                        $claimToken,
                        [
                            'mime_type' => $validated['mime_type'] ?? $file->getMimeType(),
                            'original_filename' => $validated['original_filename'] ?? $file->getClientOriginalName(),
                            'file_size_bytes' => $fileSizeBytes,
                            'provider_file_id' => $validated['provider_file_id'] ?? null,
                        ],
                    );
                } finally {
                    fclose($stream);
                }
            } else {
                $stored = $storeTelegramAccountMediaDownloadResultAction->markFailed(
                    $channel,
                    $attachment,
                    $claimToken,
                    $validated,
                );
            }
        } catch (InvalidArgumentException $exception) {
            abort(409, $exception->getMessage());
        }

        return response()->json([
            'ok' => true,
            'stored' => true,
            'attachment_id' => $stored->id,
            'download_status' => $stored->download_status,
        ]);
    }

    private function exceedsClaimedAutomaticLimit(
        Channel $channel,
        MessageAttachment $attachment,
        int $fileSizeBytes,
        bool $manualDownload,
        TelegramAccountMediaDownloadPolicy $mediaDownloadPolicy,
    ): bool {
        if ($manualDownload) {
            return false;
        }

        $maxBytes = $attachment->media_download_max_bytes
            ?? $mediaDownloadPolicy->automaticMaxBytes($channel);

        return $fileSizeBytes > max(0, (int) $maxBytes);
    }

    private function authorizeGatewayRequest(Request $request, Channel $channel): void
    {
        abort_unless(
            $channel->is_active
                && $channel->platform === Channel::PLATFORM_TELEGRAM
                && $channel->connection_type === Channel::CONNECTION_TYPE_ACCOUNT,
            404,
        );

        $expectedSecret = trim((string) config('bots.telegram_account.gateway_shared_secret', ''));
        $providedSecret = trim((string) $request->bearerToken());

        abort_unless(
            $expectedSecret !== '' && $providedSecret !== '' && hash_equals($expectedSecret, $providedSecret),
            403,
        );
    }
}
