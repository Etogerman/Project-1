<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactQuestionnaireRun;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use Database\Seeders\ProfileQuestionnaireSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class QuestionnaireStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_questionnaire_storage_tables_and_feature_flag_exist(): void
    {
        $this->assertSame('legacy_collector', config('bots.data_collection.profile_collection_engine'));

        $this->assertTrue(Schema::hasTable('questionnaire_templates'));
        $this->assertTrue(Schema::hasColumns('questionnaire_templates', [
            'key',
            'name',
            'status',
            'published_version_id',
        ]));

        $this->assertTrue(Schema::hasTable('questionnaire_template_versions'));
        $this->assertTrue(Schema::hasColumns('questionnaire_template_versions', [
            'questionnaire_template_id',
            'version',
            'status',
            'fields_payload',
            'published_at',
        ]));

        $this->assertTrue(Schema::hasTable('contact_questionnaire_runs'));
        $this->assertTrue(Schema::hasColumns('contact_questionnaire_runs', [
            'contact_id',
            'questionnaire_template_id',
            'questionnaire_template_version_id',
            'status',
            'current_field_key',
            'started_dialog_id',
            'last_dialog_id',
            'started_by_block_id',
            'awaiting_block_id',
            'scenario_run_id',
            'started_at',
            'completed_at',
            'cancelled_at',
            'operator_requested_at',
            'reset_at',
            'reset_by',
        ]));

        $this->assertTrue(Schema::hasTable('contact_questionnaire_answers'));
        $this->assertTrue(Schema::hasTable('contact_questionnaire_attempts'));
    }

    public function test_profile_questionnaire_seeder_creates_published_profile_template(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->with('publishedVersion')
            ->sole();

        $this->assertSame('Профильная анкета', $template->name);
        $this->assertSame(QuestionnaireTemplate::STATUS_PUBLISHED, $template->status);
        $this->assertNotNull($template->published_version_id);
        $this->assertSame(QuestionnaireTemplateVersion::STATUS_PUBLISHED, $template->publishedVersion->status);

        $fields = $template->publishedVersion->fields_payload;
        $fieldKeys = collect($fields)->pluck('field_key')->all();

        $this->assertSame([
            'gender',
            'first_name',
            'city',
            'age_range',
        ], $fieldKeys);
        $this->assertNotContains('phone', $fieldKeys);
    }

    public function test_only_one_awaiting_questionnaire_run_is_allowed_per_contact(): void
    {
        $contact = Contact::factory()->create();
        [$templateA, $versionA] = $this->createPublishedTemplate('profile_a');
        [$templateB, $versionB] = $this->createPublishedTemplate('profile_b');

        ContactQuestionnaireRun::query()->create([
            'contact_id' => $contact->id,
            'questionnaire_template_id' => $templateA->id,
            'questionnaire_template_version_id' => $versionA->id,
            'status' => ContactQuestionnaireRun::STATUS_AWAITING_ANSWER,
            'current_field_key' => 'gender',
            'started_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ContactQuestionnaireRun::query()->create([
            'contact_id' => $contact->id,
            'questionnaire_template_id' => $templateB->id,
            'questionnaire_template_version_id' => $versionB->id,
            'status' => ContactQuestionnaireRun::STATUS_AWAITING_ANSWER,
            'current_field_key' => 'city',
            'started_at' => now(),
        ]);
    }

    public function test_only_one_active_run_per_template_is_allowed_per_contact(): void
    {
        $contact = Contact::factory()->create();
        [$template, $version] = $this->createPublishedTemplate('profile');

        ContactQuestionnaireRun::query()->create([
            'contact_id' => $contact->id,
            'questionnaire_template_id' => $template->id,
            'questionnaire_template_version_id' => $version->id,
            'status' => ContactQuestionnaireRun::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        ContactQuestionnaireRun::query()->create([
            'contact_id' => $contact->id,
            'questionnaire_template_id' => $template->id,
            'questionnaire_template_version_id' => $version->id,
            'status' => ContactQuestionnaireRun::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);
    }

    /**
     * @return array{QuestionnaireTemplate, QuestionnaireTemplateVersion}
     */
    private function createPublishedTemplate(string $key): array
    {
        $template = QuestionnaireTemplate::query()->create([
            'key' => $key,
            'name' => $key,
            'status' => QuestionnaireTemplate::STATUS_DRAFT,
        ]);

        $version = QuestionnaireTemplateVersion::query()->create([
            'questionnaire_template_id' => $template->id,
            'version' => 1,
            'status' => QuestionnaireTemplateVersion::STATUS_PUBLISHED,
            'fields_payload' => [],
            'published_at' => now(),
        ]);

        $template->forceFill([
            'status' => QuestionnaireTemplate::STATUS_PUBLISHED,
            'published_version_id' => $version->id,
        ])->save();

        return [$template, $version];
    }
}
