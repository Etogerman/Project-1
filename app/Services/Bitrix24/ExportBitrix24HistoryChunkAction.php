<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24HistoryExportChunkData;
use App\Models\Contact;

class ExportBitrix24HistoryChunkAction
{
    private const ENTITY_TYPE_CONTACT = 'contact';

    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly BuildBitrix24TimelineCommentAction $buildTimelineCommentAction,
    ) {}

    public function handle(Contact $contact, Bitrix24HistoryExportChunkData $chunk): ?string
    {
        $response = $this->apiClient->call('crm.timeline.comment.add', [
            'fields' => [
                'ENTITY_ID' => $contact->bitrix24_contact_id,
                'ENTITY_TYPE' => self::ENTITY_TYPE_CONTACT,
                'COMMENT' => $this->buildTimelineCommentAction->handle($chunk),
            ],
        ]);

        if (! $response->successful) {
            throw new Bitrix24ApiException(
                sprintf(
                    'Bitrix24 history export failed for contact #%d: %s',
                    $contact->id,
                    $response->errorMessage ?? 'Unknown error.',
                ),
            );
        }

        return $this->extractTimelineEntryId($response->result);
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
}
