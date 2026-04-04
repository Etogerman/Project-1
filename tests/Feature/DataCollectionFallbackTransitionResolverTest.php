<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\DataCollection\DataCollectionFallbackTransitionResolver;
use Tests\TestCase;

class DataCollectionFallbackTransitionResolverTest extends TestCase
{
    private DataCollectionFallbackTransitionResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new DataCollectionFallbackTransitionResolver();
    }

    public function test_it_resolves_common_retry_limit_actions(): void
    {
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_RESIDENCE_CITY,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_FIRST_NAME),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_CITY,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_COUNTRY, false),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_COUNTRY, true),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_CITY),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_TERMINAL_SKIP,
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_AGE_RANGE),
        );
    }

    public function test_it_resolves_common_local_skip_actions(): void
    {
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_RESIDENCE_CITY,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_FIRST_NAME),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_CITY,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_COUNTRY, false),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_COUNTRY, true),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_MOVE_TO_AGE_RANGE,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_CITY),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_TERMINAL_SKIP,
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_AGE_RANGE),
        );
    }

    public function test_it_leaves_russian_region_confirm_to_job_special_case(): void
    {
        $this->assertNull(
            $this->resolver->resolveAfterRetryLimit(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM),
        );
        $this->assertNull(
            $this->resolver->resolveAfterLocalSkip(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM),
        );
    }

    public function test_it_falls_back_to_terminal_skip_for_unknown_fields(): void
    {
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_TERMINAL_SKIP,
            $this->resolver->resolveAfterRetryLimit('unknown_field'),
        );
        $this->assertSame(
            DataCollectionFallbackTransitionResolver::ACTION_TERMINAL_SKIP,
            $this->resolver->resolveAfterLocalSkip(null),
        );
    }
}
