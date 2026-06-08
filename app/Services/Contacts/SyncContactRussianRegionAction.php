<?php

namespace App\Services\Contacts;

use App\Models\Contact;
use App\Services\DataCollection\ResolveRussianRegionAction;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncContactRussianRegionAction
{
    public function __construct(
        private readonly ResolveRussianRegionAction $resolveRussianRegionAction,
    ) {}

    public function handle(Contact $contact, bool $allowClarification = false): Contact
    {
        try {
            $resolved = $this->resolveRussianRegionAction->handle($contact->country, $contact->city);
        } catch (Throwable $throwable) {
            Log::warning('contact.region_resolution_failed', [
                'contact_id' => $contact->id,
                'country' => $contact->country,
                'city' => $contact->city,
                'exception_class' => $throwable::class,
                'error' => $throwable->getMessage(),
            ]);

            $resolved = [
                'status' => Contact::REGION_STATUS_UNKNOWN,
                'region' => null,
                'candidate_regions' => [],
            ];
        }

        $status = is_string($resolved['status'] ?? null)
            ? $resolved['status']
            : Contact::REGION_STATUS_UNKNOWN;
        $region = is_string($resolved['region'] ?? null)
            ? trim((string) $resolved['region'])
            : null;
        $candidateRegions = is_array($resolved['candidate_regions'] ?? null)
            ? array_values(array_filter($resolved['candidate_regions'], fn (mixed $value): bool => is_string($value) && trim($value) !== ''))
            : [];
        $source = in_array($resolved['source'] ?? null, [
            Contact::REGION_SOURCE_AI,
            Contact::REGION_SOURCE_DICTIONARY,
        ], true)
            ? $resolved['source']
            : null;

        if ($status === Contact::REGION_STATUS_RESOLVED && filled($region)) {
            $contact->forceFill([
                'region' => $region,
                'region_status' => Contact::REGION_STATUS_RESOLVED,
                'region_source' => $source,
                'pending_region_candidates' => null,
            ])->save();

            return $contact;
        }

        if ($allowClarification && $status === Contact::REGION_STATUS_CLARIFICATION_PENDING && count($candidateRegions) >= 2 && count($candidateRegions) <= 4) {
            $contact->forceFill([
                'region' => null,
                'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
                'region_source' => $source,
                'pending_region_candidates' => $candidateRegions,
            ])->save();

            return $contact;
        }

        if ($allowClarification && $status === Contact::REGION_STATUS_AMBIGUOUS && count($candidateRegions) >= 5) {
            $contact->forceFill([
                'region' => null,
                'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
                'region_source' => $source,
                'pending_region_candidates' => $candidateRegions,
            ])->save();

            return $contact;
        }

        if ($status === Contact::REGION_STATUS_CLARIFICATION_PENDING) {
            $status = Contact::REGION_STATUS_AMBIGUOUS;
        }

        $contact->forceFill([
            'region' => null,
            'region_status' => $status,
            'region_source' => $status === Contact::REGION_STATUS_OUT_OF_SCOPE ? $contact->region_source : null,
            'pending_region_candidates' => null,
        ])->save();

        return $contact;
    }
}
