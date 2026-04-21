<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewBitrix24Connection extends ViewRecord
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    public string $webhookEventCallbackTypeFilter = '';

    public string $webhookEventProcessingStatusFilter = '';

    public string $syncLogStatusFilter = '';

    public function getTitle(): string|Htmlable
    {
        return 'Bitrix24';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Bitrix24';
    }

    public function getSubheading(): ?string
    {
        return $this->getRecord()->portal_domain;
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    public function getWebhookEventCards(): array
    {
        $query = $this->getRecord()->webhookEvents()
            ->orderByDesc('id');

        if ($this->webhookEventCallbackTypeFilter !== '') {
            $query->where('callback_type', $this->webhookEventCallbackTypeFilter);
        }

        if ($this->webhookEventProcessingStatusFilter !== '') {
            $query->where('processing_status', $this->webhookEventProcessingStatusFilter);
        }

        return $query
            ->limit(20)
            ->get()
            ->map(fn (Bitrix24WebhookEvent $event): array => [
                'created_at_label' => $this->formatTimestamp($event->created_at),
                'callback_type_label' => Bitrix24ConnectionResource::formatWebhookEventCallbackType($event->callback_type),
                'processing_status_label' => Bitrix24ConnectionResource::formatWebhookEventProcessingStatus($event->processing_status),
                'processing_status_tone' => Bitrix24ConnectionResource::getWebhookEventProcessingStatusTone($event->processing_status),
                'event_name' => filled($event->event_name) ? (string) $event->event_name : '—',
                'attempts' => $event->attempts,
                'failed_at_label' => $this->formatTimestamp($event->failed_at),
                'failure_reason' => filled($event->failure_reason) ? (string) $event->failure_reason : '—',
            ])
            ->all();
    }

    /**
     * @return list<array<string, string|int|null|bool>>
     */
    public function getSyncLogCards(): array
    {
        $query = $this->getRecord()->syncLogs()
            ->orderByDesc('id');

        if ($this->syncLogStatusFilter !== '') {
            $query->where('status', $this->syncLogStatusFilter);
        }

        return $query
            ->limit(20)
            ->get()
            ->map(fn (Bitrix24SyncLog $log): array => [
                'created_at_label' => $this->formatTimestamp($log->created_at),
                'direction_label' => Bitrix24ConnectionResource::formatSyncLogDirection($log->direction),
                'status_label' => Bitrix24ConnectionResource::formatSyncLogStatus($log->status),
                'status_tone' => Bitrix24ConnectionResource::getSyncLogStatusTone($log->status),
                'operation' => filled($log->operation) ? (string) $log->operation : '—',
                'entity_type' => filled($log->entity_type) ? (string) $log->entity_type : '—',
                'entity_id' => filled($log->entity_id) ? (string) $log->entity_id : '—',
                'http_status' => $log->http_status,
                'error_code' => filled($log->error_code) ? (string) $log->error_code : '—',
                'error_message' => filled($log->error_message) ? (string) $log->error_message : '—',
                'request_payload_pretty' => $this->formatPayload($log->request_payload),
                'response_payload_pretty' => $this->formatPayload($log->response_payload),
                'has_request_payload' => $log->request_payload !== null,
                'has_response_payload' => $log->response_payload !== null,
            ])
            ->all();
    }

    protected function formatTimestamp(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '—';
        }

        return $value->format('d.m.Y H:i:s');
    }

    protected function formatPayload(mixed $payload): string
    {
        if ($payload === null) {
            return '—';
        }

        if (is_string($payload)) {
            return $payload;
        }

        $encoded = json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $encoded !== false ? $encoded : '—';
    }
}
