<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\DataCollection\ResolveNextDataCollectionFieldAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveNextDataCollectionFieldActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_first_name_when_profile_is_empty(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        $this->assertSame(
            Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            app(ResolveNextDataCollectionFieldAction::class)->handle($contact),
        );
    }

    public function test_it_returns_country_when_first_name_is_filled(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => null,
            'city' => null,
            'age_range' => null,
        ]);

        $this->assertSame(
            Contact::DATA_COLLECTION_FIELD_COUNTRY,
            app(ResolveNextDataCollectionFieldAction::class)->handle($contact),
        );
    }

    public function test_it_returns_city_when_first_name_and_country_are_filled(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => null,
            'age_range' => null,
        ]);

        $this->assertSame(
            Contact::DATA_COLLECTION_FIELD_CITY,
            app(ResolveNextDataCollectionFieldAction::class)->handle($contact),
        );
    }

    public function test_it_returns_age_range_when_location_profile_is_filled(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => null,
        ]);

        $this->assertSame(
            Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            app(ResolveNextDataCollectionFieldAction::class)->handle($contact),
        );
    }

    public function test_it_returns_null_when_profile_is_complete(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '30_39',
        ]);

        $this->assertNull(app(ResolveNextDataCollectionFieldAction::class)->handle($contact));
    }
}
