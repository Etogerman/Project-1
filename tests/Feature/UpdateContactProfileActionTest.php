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
}
