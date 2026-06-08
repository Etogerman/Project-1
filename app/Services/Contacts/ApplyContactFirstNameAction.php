<?php

namespace App\Services\Contacts;

use App\Data\Contacts\FirstNameApplyResult;
use App\Data\Contacts\FirstNameResolutionWriteContext;
use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Services\Analytics\FirstNameResolutionAnalyticsService;
use Illuminate\Support\Facades\DB;

class ApplyContactFirstNameAction
{
    private const RESOLUTION_METHOD_NOT_PROVIDED = '__not_provided__';

    public const REASON_AUTO_INBOUND = 'auto_inbound';

    public const REASON_SCENARIO_CONFIRMED = 'scenario_confirmed';

    public const REASON_MANUAL_EDIT = 'manual_edit';

    public function __construct(
        private readonly ResolveRootContactAction $resolveRootContactAction,
        private readonly FirstNameResolutionAnalyticsService $firstNameResolutionAnalyticsService,
    ) {}

    public function handle(
        Contact $contact,
        ?string $newFirstName,
        string $source,
        string $reason,
        ?string $resolutionMethod = self::RESOLUTION_METHOD_NOT_PROVIDED,
        ?FirstNameResolutionWriteContext $writeContext = null,
    ): FirstNameApplyResult {
        $rootContact = $this->resolveRootContactAction->handle($contact);
        $normalizedFirstName = $this->normalizeNullableString($newFirstName);
        $previousValue = $this->normalizeNullableString($rootContact->first_name);
        $previousSource = $this->normalizeSource($rootContact->first_name_source);
        $previousResolutionMethod = $this->normalizeResolutionMethod($rootContact->first_name_resolution_method);

        if ($normalizedFirstName === null) {
            return new FirstNameApplyResult(
                changed: false,
                bitrix24RelevantChanged: false,
                previousValue: $previousValue,
                newValue: $previousValue,
                previousSource: $previousSource,
                newSource: $previousSource,
                previousResolutionMethod: $previousResolutionMethod,
                newResolutionMethod: $previousResolutionMethod,
            );
        }

        $validatedSource = $this->assertValidSource($source);
        $validatedReason = $this->assertValidReason($reason);
        $resolutionMethodWasProvided = $resolutionMethod !== self::RESOLUTION_METHOD_NOT_PROVIDED;
        $newResolutionMethod = $resolutionMethodWasProvided
            ? $this->assertValidResolutionMethod($resolutionMethod)
            : null;

        if (! $this->canOverwrite($previousSource, $validatedSource)) {
            return new FirstNameApplyResult(
                changed: false,
                bitrix24RelevantChanged: false,
                previousValue: $previousValue,
                newValue: $previousValue,
                previousSource: $previousSource,
                newSource: $previousSource,
                previousResolutionMethod: $previousResolutionMethod,
                newResolutionMethod: $previousResolutionMethod,
            );
        }

        $nameOrSourceChanged = $previousValue !== $normalizedFirstName || $previousSource !== $validatedSource;

        if (! $resolutionMethodWasProvided && ! $nameOrSourceChanged) {
            $newResolutionMethod = $previousResolutionMethod;
        }

        if (! $nameOrSourceChanged && $previousResolutionMethod === $newResolutionMethod) {
            return new FirstNameApplyResult(
                changed: false,
                bitrix24RelevantChanged: false,
                previousValue: $previousValue,
                newValue: $previousValue,
                previousSource: $previousSource,
                newSource: $previousSource,
                previousResolutionMethod: $previousResolutionMethod,
                newResolutionMethod: $previousResolutionMethod,
            );
        }

        DB::transaction(function () use (
            $rootContact,
            $normalizedFirstName,
            $validatedSource,
            $newResolutionMethod,
            $previousValue,
            $previousSource,
            $previousResolutionMethod,
            $validatedReason,
        ): void {
            $rootContact->forceFill([
                'first_name' => $normalizedFirstName,
                'first_name_source' => $validatedSource,
                'first_name_resolution_method' => $newResolutionMethod,
            ])->save();

            if ($previousValue !== null || $previousSource !== null || $previousResolutionMethod !== null) {
                $this->logFirstNameChanged(
                    contact: $rootContact,
                    previousValue: $previousValue,
                    newValue: $normalizedFirstName,
                    previousSource: $previousSource,
                    newSource: $validatedSource,
                    previousResolutionMethod: $previousResolutionMethod,
                    newResolutionMethod: $newResolutionMethod,
                    reason: $validatedReason,
                );
            }
        });

        $result = new FirstNameApplyResult(
            changed: true,
            bitrix24RelevantChanged: $nameOrSourceChanged,
            previousValue: $previousValue,
            newValue: $normalizedFirstName,
            previousSource: $previousSource,
            newSource: $validatedSource,
            previousResolutionMethod: $previousResolutionMethod,
            newResolutionMethod: $newResolutionMethod,
        );

        $this->firstNameResolutionAnalyticsService->recordNameWritten($rootContact, $result, $writeContext);

        return $result;
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
        $previousResolutionMethod = $this->normalizeResolutionMethod($rootContact->first_name_resolution_method);

        if ($previousValue === null && $previousSource === null && $previousResolutionMethod === null) {
            return new FirstNameApplyResult(
                changed: false,
                bitrix24RelevantChanged: false,
                previousValue: null,
                newValue: null,
                previousSource: null,
                newSource: null,
                previousResolutionMethod: null,
                newResolutionMethod: null,
            );
        }

        DB::transaction(function () use ($rootContact, $previousValue, $previousSource, $previousResolutionMethod, $validatedReason): void {
            $rootContact->forceFill([
                'first_name' => null,
                'first_name_source' => null,
                'first_name_resolution_method' => null,
            ])->save();

            $this->logFirstNameChanged(
                contact: $rootContact,
                previousValue: $previousValue,
                newValue: null,
                previousSource: $previousSource,
                newSource: null,
                previousResolutionMethod: $previousResolutionMethod,
                newResolutionMethod: null,
                reason: $validatedReason,
            );
        });

        return new FirstNameApplyResult(
            changed: true,
            bitrix24RelevantChanged: $previousValue !== null || $previousSource !== null,
            previousValue: $previousValue,
            newValue: null,
            previousSource: $previousSource,
            newSource: null,
            previousResolutionMethod: $previousResolutionMethod,
            newResolutionMethod: null,
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
        ?string $previousResolutionMethod,
        ?string $newResolutionMethod,
        string $reason,
    ): void {
        $contact->timelineEvents()->create([
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
            'payload' => [
                'previous_value' => $previousValue,
                'new_value' => $newValue,
                'previous_source' => $previousSource,
                'new_source' => $newSource,
                'previous_resolution_method' => $previousResolutionMethod,
                'new_resolution_method' => $newResolutionMethod,
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

    private function assertValidResolutionMethod(?string $resolutionMethod): ?string
    {
        $normalized = $this->normalizeResolutionMethod($resolutionMethod);

        if ($normalized === null) {
            return null;
        }

        if (! in_array($normalized, Contact::allowedFirstNameResolutionMethods(), true)) {
            throw new ContactFirstNameException('Unsupported first name resolution method.');
        }

        return $normalized;
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

    private function normalizeResolutionMethod(mixed $value): ?string
    {
        if (! is_string($value) || $value === self::RESOLUTION_METHOD_NOT_PROVIDED) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
