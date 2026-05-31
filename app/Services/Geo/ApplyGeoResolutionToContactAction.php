<?php

namespace App\Services\Geo;

use App\Models\Contact;
use App\Models\Dialog;
use App\Models\GeoResolutionEvent;
use App\Models\Message;
use Illuminate\Support\Facades\DB;

class ApplyGeoResolutionToContactAction
{
    /**
     * @param  array<string, mixed>  $result
     */
    public function handle(
        Contact $contact,
        array $result,
        ?Dialog $dialog = null,
        ?Message $message = null,
    ): GeoResolutionEvent {
        return DB::transaction(function () use ($contact, $result, $dialog, $message): GeoResolutionEvent {
            if (($result['status'] ?? null) === ResolveGeoCityAction::STATUS_MATCHED_CITY) {
                $contact->forceFill([
                    'country' => $this->nullableString($result['country'] ?? null),
                    'region' => $this->nullableString($result['region'] ?? null),
                    'city' => $this->nullableString($result['city'] ?? null),
                ])->save();
            }

            return $this->createEvent($contact, $result, $dialog, $message);
        });
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function createEvent(
        Contact $contact,
        array $result,
        ?Dialog $dialog = null,
        ?Message $message = null,
    ): GeoResolutionEvent {
        return GeoResolutionEvent::query()->create([
            'contact_id' => $contact->id,
            'dialog_id' => $dialog?->id,
            'message_id' => $message?->id,
            'status' => $this->requiredString($result['status'] ?? null),
            'source_text' => $this->nullableString($result['source_text'] ?? null),
            'matched_alias' => $this->nullableString($result['matched_alias'] ?? null),
            'geo_alias_id' => $this->nullableInt($result['geo_alias_id'] ?? null),
            'country_id' => $this->nullableInt($result['country_id'] ?? null),
            'region_id' => $this->nullableInt($result['region_id'] ?? null),
            'city_id' => $this->nullableInt($result['city_id'] ?? null),
            'country' => $this->nullableString($result['country'] ?? null),
            'region' => $this->nullableString($result['region'] ?? null),
            'city' => $this->nullableString($result['city'] ?? null),
            'confidence' => $this->nullableInt($result['confidence'] ?? null),
            'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : null,
        ]);
    }

    private function requiredString(mixed $value): string
    {
        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : ResolveGeoCityAction::STATUS_FAILED;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
