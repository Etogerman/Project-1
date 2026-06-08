<?php

namespace App\Services\Analytics;

use App\Data\Contacts\FirstNameApplyResult;
use App\Data\Contacts\FirstNameResolutionWriteContext;
use App\Models\Contact;
use App\Models\ContactFirstNameResolutionEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class FirstNameResolutionAnalyticsService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function recordResolutionAttempt(
        Contact $contact,
        string $source,
        string $result,
        ?string $clientText = null,
        ?int $dialogId = null,
        ?int $channelId = null,
        ?int $scenarioId = null,
        ?string $scenarioBlockId = null,
        ?int $messageId = null,
        ?int $aiRequestId = null,
        ?int $matchedDictionaryEntryId = null,
        ?string $foundFirstName = null,
        ?string $resolvedFirstName = null,
        ?string $correlationId = null,
        array $payload = [],
    ): ?ContactFirstNameResolutionEvent {
        if (! $this->tableReady()) {
            return null;
        }

        try {
            return ContactFirstNameResolutionEvent::query()->create([
                'event_type' => ContactFirstNameResolutionEvent::EVENT_TYPE_RESOLUTION_ATTEMPT,
                'correlation_id' => $correlationId ?: (string) Str::uuid(),
                'contact_id' => $contact->id,
                'dialog_id' => $dialogId,
                'channel_id' => $channelId,
                'scenario_id' => $scenarioId,
                'scenario_block_id' => $scenarioBlockId,
                'message_id' => $messageId,
                'ai_request_id' => $aiRequestId,
                'source' => $source,
                'result' => $result,
                'client_text_preview' => $this->preview($clientText, 1000),
                'matched_dictionary_entry_id' => $matchedDictionaryEntryId,
                'found_first_name' => $this->nullableText($foundFirstName),
                'resolved_first_name' => $this->nullableText($resolvedFirstName),
                'payload' => $payload,
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('first_name_resolution.resolution_attempt_failed', $throwable, [
                'contact_id' => $contact->id,
                'source' => $source,
                'result' => $result,
            ]);

            return null;
        }
    }

    public function recordNameWritten(
        Contact $contact,
        FirstNameApplyResult $result,
        ?FirstNameResolutionWriteContext $context = null,
    ): ?ContactFirstNameResolutionEvent {
        if (! $this->tableReady() || ! $result->changed) {
            return null;
        }

        try {
            return ContactFirstNameResolutionEvent::query()->create([
                'event_type' => ContactFirstNameResolutionEvent::EVENT_TYPE_NAME_WRITTEN,
                'correlation_id' => $context?->correlationId ?: (string) Str::uuid(),
                'contact_id' => $contact->id,
                'dialog_id' => $context?->dialogId,
                'channel_id' => $context?->channelId,
                'scenario_id' => $context?->scenarioId,
                'scenario_block_id' => $context?->scenarioBlockId,
                'message_id' => $context?->messageId,
                'ai_request_id' => $context?->aiRequestId,
                'resolution_attempt_event_id' => $context?->resolutionAttemptEventId,
                'source' => $this->sourceForResolutionMethod($result->newResolutionMethod),
                'result' => ContactFirstNameResolutionEvent::RESULT_WRITTEN,
                'old_first_name' => $result->previousValue,
                'new_first_name' => $result->newValue,
                'written_first_name' => $result->newValue,
                'first_name_source' => $result->newSource,
                'first_name_resolution_method' => $result->newResolutionMethod,
                'payload' => [],
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('first_name_resolution.name_written_failed', $throwable, [
                'contact_id' => $contact->id,
                'method' => $result->newResolutionMethod,
            ]);

            return null;
        }
    }

    private function sourceForResolutionMethod(?string $method): string
    {
        return match ($method) {
            Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP => ContactFirstNameResolutionEvent::SOURCE_DICTIONARY,
            Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS => ContactFirstNameResolutionEvent::SOURCE_AI,
            Contact::FIRST_NAME_RESOLUTION_METHOD_OPERATOR_MANUAL => ContactFirstNameResolutionEvent::SOURCE_OPERATOR,
            Contact::FIRST_NAME_RESOLUTION_METHOD_MESSENGER_PROFILE => ContactFirstNameResolutionEvent::SOURCE_MESSENGER_PROFILE,
            default => ContactFirstNameResolutionEvent::SOURCE_SCENARIO,
        };
    }

    private function tableReady(): bool
    {
        return Schema::hasTable('contact_first_name_resolution_events');
    }

    private function preview(?string $value, int $limit): ?string
    {
        $text = $this->nullableText($value);

        return $text === null ? null : mb_substr($text, 0, $limit);
    }

    private function nullableText(?string $value): ?string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim((string) $value));

        if (! is_string($normalized) || $normalized === '') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logFailure(string $event, Throwable $throwable, array $context): void
    {
        Log::warning($event, $context + [
            'exception' => get_class($throwable),
            'error_message' => mb_substr($throwable->getMessage(), 0, 1000),
        ]);
    }
}
