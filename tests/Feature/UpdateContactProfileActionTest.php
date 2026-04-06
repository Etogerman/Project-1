<?php

namespace Tests\Feature;

use App\Jobs\CalculateDistanceToMoscowJob;
use App\Models\Contact;
use App\Services\Contacts\UpdateContactProfileAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateContactProfileActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_action_syncs_region_after_manual_city_and_country_update(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
            'Республика Татарстан',
        ]);
        config()->set('russian_region_cities.cities', [
            'москва' => [
                'city' => 'Москва',
                'aliases' => [],
                'regions' => ['Московская область'],
            ],
        ]);

        Queue::fake([CalculateDistanceToMoscowJob::class]);
        Http::fake();

        $contact = Contact::factory()->create([
            'country' => null,
            'city' => null,
            'region' => null,
            'region_status' => null,
            'region_source' => null,
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($contact, [
            'country' => 'Россия',
            'city' => 'Москва',
            'region' => null,
        ]);

        $this->assertSame('Россия', $updated->country);
        $this->assertSame('Москва', $updated->city);
        $this->assertSame('Московская область', $updated->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $updated->region_status);
        $this->assertSame(Contact::REGION_SOURCE_AI, $updated->region_source);
        Queue::assertPushed(CalculateDistanceToMoscowJob::class, function (CalculateDistanceToMoscowJob $job) use ($contact): bool {
            return $job->contactId === $contact->id;
        });
        Http::assertNothingSent();
    }

    public function test_action_updates_root_when_merged_contact_is_passed(): void
    {
        Queue::fake([CalculateDistanceToMoscowJob::class]);
        Http::fake();

        $root = Contact::factory()->create([
            'first_name' => null,
            'country' => null,
            'city' => null,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'first_name' => 'Старое имя',
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($merged, [
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'region' => 'Московская область',
        ]);

        $this->assertSame($root->id, $updated->id);
        $this->assertSame('Герман', $root->fresh()->first_name);
        $this->assertSame('Россия', $root->fresh()->country);
        $this->assertSame('Москва', $root->fresh()->city);
        $this->assertSame('Московская область', $root->fresh()->region);
        $this->assertSame('Старое имя', $merged->fresh()->first_name);

        Queue::assertPushed(CalculateDistanceToMoscowJob::class, function (CalculateDistanceToMoscowJob $job) use ($root): bool {
            return $job->contactId === $root->id;
        });
    }

    public function test_action_advances_active_collector_when_manual_edit_fills_current_field(): void
    {
        Queue::fake();
        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => null,
            'country' => null,
            'city' => null,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_attempts_count' => 2,
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($contact, [
            'first_name' => 'Герман',
            'last_name' => null,
            'gender' => null,
            'birth_date' => null,
            'age_years' => null,
            'age_range' => null,
            'country' => null,
            'city' => null,
            'region' => null,
        ]);

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $updated->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_RESIDENCE_CITY, $updated->data_collection_current_field);
        $this->assertSame(0, $updated->data_collection_attempts_count);
        $this->assertNull($updated->data_collection_last_prompted_field);
        $this->assertNotNull($updated->data_collection_current_field_started_at);
    }

    public function test_action_completes_active_collector_when_manual_edit_finishes_last_missing_field(): void
    {
        Queue::fake();
        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'country' => 'Германия',
            'city' => 'Берлин',
            'region' => null,
            'region_status' => Contact::REGION_STATUS_OUT_OF_SCOPE,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_AGE_RANGE,
            'data_collection_attempts_count' => 1,
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($contact, [
            'first_name' => 'Герман',
            'last_name' => null,
            'gender' => null,
            'birth_date' => null,
            'age_years' => null,
            'age_range' => '30_39',
            'country' => 'Германия',
            'city' => 'Берлин',
            'region' => null,
        ]);

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $updated->data_collection_status);
        $this->assertNull($updated->data_collection_current_field);
        $this->assertNull($updated->data_collection_last_prompted_field);
        $this->assertNull($updated->data_collection_current_field_started_at);
        $this->assertNotNull($updated->data_collection_completed_at);
    }

    public function test_action_keeps_active_collector_field_when_manual_edit_does_not_change_progression(): void
    {
        Queue::fake();
        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'last_name' => null,
            'country' => 'Россия',
            'city' => null,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_CITY,
            'data_collection_attempts_count' => 2,
        ]);

        $updated = app(UpdateContactProfileAction::class)->handle($contact, [
            'first_name' => 'Герман',
            'last_name' => 'Абрикосов',
            'gender' => null,
            'birth_date' => null,
            'age_years' => null,
            'age_range' => null,
            'country' => 'Россия',
            'city' => null,
            'region' => null,
        ]);

        $this->assertSame(Contact::DATA_COLLECTION_STATUS_ACTIVE, $updated->data_collection_status);
        $this->assertSame(Contact::DATA_COLLECTION_FIELD_CITY, $updated->data_collection_current_field);
        $this->assertSame(2, $updated->data_collection_attempts_count);
        $this->assertSame('Абрикосов', $updated->last_name);
    }
}
