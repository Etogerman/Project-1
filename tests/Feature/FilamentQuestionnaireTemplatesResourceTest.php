<?php

namespace Tests\Feature;

use App\Filament\Resources\QuestionnaireTemplates\Pages\ManageQuestionnaireTemplates;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\User;
use Database\Seeders\ProfileQuestionnaireSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class FilamentQuestionnaireTemplatesResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_admin_can_open_questionnaire_templates_resource(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $template = QuestionnaireTemplate::query()->where('key', QuestionnaireTemplate::KEY_PROFILE)->sole();

        $this->actingAs($admin)
            ->get('/admin/questionnaire-templates')
            ->assertOk()
            ->assertSee('Анкеты');

        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', QuestionnaireTemplate::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $template));

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertTableActionExists('editDraft', null, $template)
            ->assertTableActionExists('publishDraft', null, $template);
    }

    public function test_employee_cannot_open_questionnaire_templates_resource(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
            'role' => User::ROLE_EMPLOYEE,
        ]);

        $this->actingAs($employee)
            ->get('/admin/questionnaire-templates')
            ->assertForbidden();

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', QuestionnaireTemplate::class));
    }

    public function test_admin_can_save_json_draft_and_publish_it(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->with('publishedVersion')
            ->sole();
        $payload = $template->publishedVersion->fields_payload;
        $payload[0]['prompts'] = ['Выбери пол для локального теста'];

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->callTableAction('editDraft', $template, [
                'fields_payload_json' => $this->encodePayload($payload),
            ])
            ->assertHasNoTableActionErrors();

        $template->refresh();

        $this->assertNotNull($template->draftVersion);
        $this->assertSame(2, $template->draftVersion->version);

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->callTableAction('publishDraft', $template->fresh())
            ->assertHasNoTableActionErrors();

        $template->refresh();

        $this->assertSame(2, $template->publishedVersion->version);
        $this->assertSame('Выбери пол для локального теста', $template->publishedVersion->fields_payload[0]['prompts'][0]);
    }

    public function test_admin_can_create_questionnaire_with_json_draft(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $payload = [
            [
                'field_key' => 'nickname',
                'label' => 'Ник',
                'type' => 'text',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'prompts' => [
                    'Как тебя называть?',
                    'Напиши, пожалуйста, имя или ник',
                    'Подскажи, как к тебе обращаться',
                ],
            ],
        ];

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->callAction('create', [
                'key' => 'local_profile',
                'name' => 'Локальная анкета',
                'fields_payload_json' => $this->encodePayload($payload),
            ])
            ->assertHasNoActionErrors();

        $template = QuestionnaireTemplate::query()
            ->where('key', 'local_profile')
            ->with('draftVersion')
            ->sole();

        $this->assertSame('Локальная анкета', $template->name);
        $this->assertSame(QuestionnaireTemplate::STATUS_DRAFT, $template->status);
        $this->assertNotNull($template->draftVersion);
        $this->assertSame(QuestionnaireTemplateVersion::STATUS_DRAFT, $template->draftVersion->status);
        $this->assertSame('nickname', $template->draftVersion->fields_payload[0]['field_key']);
    }

    public function test_invalid_json_create_does_not_leave_empty_questionnaire(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->callAction('create', [
                'key' => 'broken_local',
                'name' => 'Сломанная анкета',
                'fields_payload_json' => '{"broken"',
            ])
            ->assertHasActionErrors();

        $this->assertDatabaseMissing('questionnaire_templates', [
            'key' => 'broken_local',
        ]);
    }

    public function test_invalid_json_draft_is_rejected(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->sole();

        Livewire::actingAs($admin)
            ->test(ManageQuestionnaireTemplates::class)
            ->callTableAction('editDraft', $template, [
                'fields_payload_json' => '{"broken"',
            ])
            ->assertHasTableActionErrors();
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    private function encodePayload(array $payload): string
    {
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
