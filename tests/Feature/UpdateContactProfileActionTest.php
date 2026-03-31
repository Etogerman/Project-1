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
}
