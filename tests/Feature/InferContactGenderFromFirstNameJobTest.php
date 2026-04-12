<?php

namespace Tests\Feature;

use App\Jobs\InferContactGenderFromFirstNameJob;
use App\Models\Contact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InferContactGenderFromFirstNameJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_saves_inferred_gender(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'gender' => 'male',
            ])),
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => null,
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($contact->id, 'Николай');

        $this->assertSame('male', $contact->fresh()->gender);
    }

    public function test_job_skips_when_gender_is_already_filled(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => 'female',
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($contact->id, 'Николай');

        Http::assertNothingSent();
        $this->assertSame('female', $contact->fresh()->gender);
    }

    public function test_job_skips_when_first_name_has_changed(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Мария',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => null,
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($contact->id, 'Николай');

        Http::assertNothingSent();
        $this->assertNull($contact->fresh()->gender);
    }

    public function test_job_keeps_gender_null_when_inference_fails(): void
    {
        config()->set('bots.gemini.api_key', null);

        $contact = Contact::factory()->create([
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => null,
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($contact->id, 'Николай');

        $this->assertNull($contact->fresh()->gender);
    }

    public function test_job_resolves_merged_contact_to_root(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response($this->geminiResponse([
                'gender' => 'male',
            ])),
        ]);

        $root = Contact::factory()->create([
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => null,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'gender' => null,
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($merged->id, 'Николай');

        $this->assertSame('male', $root->fresh()->gender);
        $this->assertNull($merged->fresh()->gender);
    }

    public function test_job_skips_when_first_name_source_is_auto(): void
    {
        config()->set('bots.gemini.api_key', 'gemini-key');

        Http::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Николай',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            'gender' => null,
        ]);

        InferContactGenderFromFirstNameJob::dispatchSync($contact->id, 'Николай');

        Http::assertNothingSent();
        $this->assertNull($contact->fresh()->gender);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function geminiResponse(array $payload): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [
                            [
                                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
