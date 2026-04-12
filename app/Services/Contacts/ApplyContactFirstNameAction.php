<?php

namespace App\Services\Contacts;

use App\Data\Contacts\FirstNameApplyResult;
use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use Illuminate\Support\Facades\DB;

class ApplyContactFirstNameAction
{
    public const REASON_AUTO_INBOUND = 'auto_inbound';

    public const REASON_SCENARIO_CONFIRMED = 'scenario_confirmed';

    public const REASON_MANUAL_EDIT = 'manual_edit';

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
    ) {}

    public function handle(Contact $contact, ?string $newFirstName, string $source, string $reason): FirstNameApplyResult
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $normalizedFirstName = $this->normalizeNullableString($newFirstName);

        if ($normalizedFirstName === null) {
            return new FirstNameApplyResult(
                changed: false,
                previousValue: $this->normalizeNullableString($rootContact->first_name),
                newValue: $this->normalizeNullableString($rootContact->first_name),
                previousSource: $this->normalizeSource($rootContact->first_name_source),
                newSource: $this->normalizeSource($rootContact->first_name_source),
            );
        }

        $validatedSource = $this->assertValidSource($source);
        $validatedReason = $this->assertValidReason($reason);
        $previousValue = $this->normalizeNullableString($rootContact->first_name);
        $previousSource = $this->normalizeSource($rootContact->first_name_source);

        if (! $this->canOverwrite($previousSource, $validatedSource)) {
            return new FirstNameApplyResult(
                changed: false,
                previousValue: $previousValue,
                newValue: $previousValue,
                previousSource: $previousSource,
                newSource: $previousSource,
            );
        }

        if ($previousValue === $normalizedFirstName && $previousSource === $validatedSource) {
            return new FirstNameApplyResult(
                changed: false,
                previousValue: $previousValue,
                newValue: $previousValue,
                previousSource: $previousSource,
                newSource: $previousSource,
            );
        }

        DB::transaction(function () use (
            $rootContact,
            $normalizedFirstName,
            $validatedSource,
            $previousValue,
            $previousSource,
            $validatedReason,
        ): void {
            $rootContact->forceFill([
                'first_name' => $normalizedFirstName,
                'first_name_source' => $validatedSource,
            ])->save();

            if ($previousValue !== null || $previousSource !== null) {
                $this->logFirstNameChanged(
                    contact: $rootContact,
                    previousValue: $previousValue,
                    newValue: $normalizedFirstName,
                    previousSource: $previousSource,
                    newSource: $validatedSource,
                    reason: $validatedReason,
                );
            }
        });

        return new FirstNameApplyResult(
            changed: true,
            previousValue: $previousValue,
            newValue: $normalizedFirstName,
            previousSource: $previousSource,
            newSource: $validatedSource,
        );
    }

    /**
     * @param  non-empty-string  $reason
     */
    public function clear(Contact $contact, string $reason): FirstNameApplyResult
    {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $validatedReason = $this->assertValidReason($reason);
        $previousValue = $this->normalizeNullableString($rootContact->first_name);
        $previousSource = $this->normalizeSource($rootContact->first_name_source);

        if ($previousValue === null && $previousSource === null) {
            return new FirstNameApplyResult(
                changed: false,
                previousValue: null,
                newValue: null,
                previousSource: null,
                newSource: null,
            );
        }

        DB::transaction(function () use ($rootContact, $previousValue, $previousSource, $validatedReason): void {
            $rootContact->forceFill([
                'first_name' => null,
                'first_name_source' => null,
            ])->save();

            $this->logFirstNameChanged(
                contact: $rootContact,
                previousValue: $previousValue,
                newValue: null,
                previousSource: $previousSource,
                newSource: null,
                reason: $validatedReason,
            );
        });

        return new FirstNameApplyResult(
            changed: true,
            previousValue: $previousValue,
            newValue: null,
            previousSource: $previousSource,
            newSource: null,
        );
    }

    private function canOverwrite(?string $currentSource, string $newSource): bool
    {
        return match ($newSource) {
            Contact::FIRST_NAME_SOURCE_AUTO => $currentSource === null || $currentSource === Contact::FIRST_NAME_SOURCE_AUTO,
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED => $currentSource !== Contact::FIRST_NAME_SOURCE_MANUAL,
            Contact::FIRST_NAME_SOURCE_MANUAL => true,
            default => false,
        };
    }

    private function logFirstNameChanged(
        Contact $contact,
        ?string $previousValue,
        ?string $newValue,
        ?string $previousSource,
        ?string $newSource,
        string $reason,
    ): void {
        $contact->timelineEvents()->create([
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
            'payload' => [
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'previous_source' => $previousSource,
                'new_source' => $newSource,
                'reason' => $reason,
            ],
            'occurred_at' => now(),
        ]);
    }

    /**
     * @return non-empty-string
     */
    private function assertValidSource(string $source): string
    {
        if (! in_array($source, Contact::allowedFirstNameSources(), true)) {
            throw new ContactFirstNameException('Unsupported first name source.');
        }

        return $source;
    }

    /**
     * @return non-empty-string
     */
    private function assertValidReason(string $reason): string
    {
        if (! in_array($reason, [
            self::REASON_AUTO_INBOUND,
            self::REASON_SCENARIO_CONFIRMED,
            self::REASON_MANUAL_EDIT,
        ], true)) {
            throw new ContactFirstNameException('Unsupported first name change reason.');
        }

        return $reason;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! is_string($normalized)) {
            return null;
        }

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeSource(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
