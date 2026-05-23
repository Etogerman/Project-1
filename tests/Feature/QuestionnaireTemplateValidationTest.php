<?php

namespace Tests\Feature;

use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\User;
use App\Services\Questionnaires\PublishQuestionnaireTemplateVersionAction;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
use App\Services\Questionnaires\ValidateQuestionnaireFieldsPayloadAction;
use Database\Seeders\ProfileQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class QuestionnaireTemplateValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_questionnaire_payload_is_valid(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->with('publishedVersion')
            ->sole();

        $validated = app(ValidateQuestionnaireFieldsPayloadAction::class)
            ->handle($template->publishedVersion->fields_payload);

        $this->assertCount(6, $validated);
        $this->assertSame('first_name', $validated[1]['field_key']);
    }

    public function test_validation_rejects_duplicate_field_key(): void
    {
        $payload = $this->validSingleChoicePayload();
        $payload[] = [
            ...$payload[0],
            'label' => 'Пол повторно',
        ];

        $this->expectException(ValidationException::class);

        app(ValidateQuestionnaireFieldsPayloadAction::class)->handle($payload);
    }

    public function test_validation_rejects_nested_dialog_required_when(): void
    {
        $payload = $this->validSingleChoicePayload();
        $payload[0]['required_when'] = '{{dialog.user.region}} == "Москва"';

        $this->expectException(ValidationException::class);

        app(ValidateQuestionnaireFieldsPayloadAction::class)->handle($payload);
    }

    public function test_validation_rejects_unknown_russian_region_option(): void
    {
        $payload = [[
            'field_key' => 'region',
            'label' => 'Регион РФ',
            'type' => 'single_choice',
            'required' => true,
            'allow_skip' => false,
            'max_attempts' => 3,
            'target' => 'contact.region',
            'overwrite_contact' => true,
            'required_when' => '{{contact.country}} == "RU"',
            'prompts' => ['Выбери регион'],
            'options' => [
                ['value' => 'Несуществующий регион', 'label' => 'Несуществующий регион'],
            ],
        ]];

        $this->expectException(ValidationException::class);

        app(ValidateQuestionnaireFieldsPayloadAction::class)->handle($payload);
    }

    public function test_draft_can_be_saved_and_then_published_as_next_version(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $template = QuestionnaireTemplate::query()->create([
            'key' => 'test_profile',
            'name' => 'Тестовая анкета',
            'status' => QuestionnaireTemplate::STATUS_DRAFT,
            'created_by' => $admin->id,
        ]);

        $draft = app(SaveQuestionnaireTemplateDraftAction::class)
            ->handle($template, $this->validSingleChoicePayload(), $admin);

        $this->assertSame(1, $draft->version);
        $this->assertSame(QuestionnaireTemplateVersion::STATUS_DRAFT, $draft->status);

        $published = app(PublishQuestionnaireTemplateVersionAction::class)
            ->handle($draft, $admin);

        $template->refresh();

        $this->assertSame(QuestionnaireTemplateVersion::STATUS_PUBLISHED, $published->status);
        $this->assertSame(QuestionnaireTemplate::STATUS_PUBLISHED, $template->status);
        $this->assertSame($published->id, $template->published_version_id);

        $nextPayload = $this->validSingleChoicePayload();
        $nextPayload[0]['prompts'] = ['Выбери пол ещё раз'];

        $nextDraft = app(SaveQuestionnaireTemplateDraftAction::class)
            ->handle($template, $nextPayload, $admin);

        $this->assertSame(2, $nextDraft->version);

        app(PublishQuestionnaireTemplateVersionAction::class)->handle($nextDraft, $admin);

        $this->assertSame(
            QuestionnaireTemplateVersion::STATUS_ARCHIVED,
            $published->fresh()->status,
        );
        $this->assertSame(
            $nextDraft->id,
            $template->fresh()->published_version_id,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function validSingleChoicePayload(): array
    {
        return [[
            'field_key' => 'gender',
            'label' => 'Пол',
            'type' => 'single_choice',
            'required' => true,
            'allow_skip' => false,
            'max_attempts' => 3,
            'target' => 'contact.gender',
            'overwrite_contact' => true,
            'required_when' => '{{contact.gender}} == "" or {{contact.gender}} == "unknown"',
            'prompts' => ['Укажи свой пол'],
            'options' => [
                ['value' => 'male', 'label' => 'Мужской'],
                ['value' => 'female', 'label' => 'Женский'],
            ],
        ]];
    }
}
