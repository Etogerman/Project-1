<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Contact;

class ExportBitrix24HistoryChunkAction
{
    private const ENTITY_TYPE_CONTACT = 'contact';

    private const ENTITY_TYPE_DEAL = 'deal';

    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly BuildBitrix24TimelineCommentAction $buildTimelineCommentAction,
    ) {}

    public function handle(Contact $contact, Bitrix24HistoryExportChunkData $chunk): ?string
    {
        return $this->exportTimelineComment(
            entityId: (string) $contact->bitrix24_contact_id,
            entityType: self::ENTITY_TYPE_CONTACT,
            chunk: $chunk,
            errorMessage: sprintf(
                'Bitrix24 history export failed for contact #%d: %s',
                $contact->id,
                '%s',
            ),
        );
    }

    public function copyToDeal(Contact $contact, Bitrix24HistoryExportChunkData $chunk): ?string
    {
        $dealId = $this->normalizeEntityId($contact->bitrix24_deal_id);

        if ($dealId === null) {
            return null;
        }

        return $this->exportTimelineComment(
            entityId: $dealId,
            entityType: self::ENTITY_TYPE_DEAL,
            chunk: $chunk,
            errorMessage: sprintf(
                'Bitrix24 deal history export failed for contact #%d and deal `%s`: %s',
                $contact->id,
                $dealId,
                '%s',
            ),
        );
    }

    private function extractTimelineEntryId(mixed $result): ?string
    {
        if (is_scalar($result)) {
            $value = trim((string) $result);

            return $value === '' ? null : $value;
        }

        if (! is_array($result)) {
            return null;
        }

        foreach (['ID', 'id', 'result'] as $key) {
            $value = $result[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    private function exportTimelineComment(
        string $entityId,
        string $entityType,
        Bitrix24HistoryExportChunkData $chunk,
        string $errorMessage,
    ): ?string {
        $response = $this->apiClient->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $entityId,
                'ENTITY_TYPE' => $entityType,
                'COMMENT' => $this->buildTimelineCommentAction->handle($chunk),
            ],
        ]);

        if (! $response->successful) {
            throw new Bitrix24ApiException(sprintf(
                $errorMessage,
                $response->errorMessage ?? 'Unknown error.',
            ));
        }

        return $this->extractTimelineEntryId($response->result);
    }

    private function normalizeEntityId(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        if ($normalized === '' || ! ctype_digit($normalized)) {
            return null;
        }

        return $normalized;
    }
}
