<?php

namespace App\Services\Geo;

use App\Models\Contact;
use App\Models\Dialog;
use App\Models\Message;
use Throwable;

class ResolveAndApplyGeoCityAction
{
    public function __construct(
        private readonly ResolveGeoCityAction $resolveGeoCityAction,
        private readonly ApplyGeoResolutionToContactAction $applyGeoResolutionToContactAction,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        Contact $contact,
        string $text,
        ?Dialog $dialog = null,
        ?Message $message = null,
    ): array {
        try {
            $result = $this->resolveGeoCityAction->handle($text);

            if (($result['status'] ?? null) === ResolveGeoCityAction::STATUS_MATCHED_CITY) {
                $this->applyGeoResolutionToContactAction->handle($contact, $result, $dialog, $message);

                return $result;
            }

            $this->applyGeoResolutionToContactAction->createEvent($contact, $result, $dialog, $message);

            return $result;
        } catch (Throwable $throwable) {
            $result = [
                'status' => ResolveGeoCityAction::STATUS_FAILED,
                'source_text' => $text,
                'payload' => [
                    'exception_class' => $throwable::class,
                    'error' => $throwable->getMessage(),
                ],
            ];

            $this->applyGeoResolutionToContactAction->createEvent($contact, $result, $dialog, $message);

            return $result;
        }
    }
}
