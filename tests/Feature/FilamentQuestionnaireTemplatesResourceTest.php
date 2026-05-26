<?php

namespace Tests\Feature;

use App\Filament\Resources\QuestionnaireTemplates\Pages\CreateQuestionnaireTemplate;
use App\Filament\Resources\QuestionnaireTemplates\Pages\EditQuestionnaireTemplate;
use App\Filament\Resources\QuestionnaireTemplates\Pages\ListQuestionnaireTemplates;
use App\Filament\Resources\QuestionnaireTemplates\QuestionnaireTemplateResource;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use App\Models\User;
use App\Services\Questionnaires\SaveQuestionnaireTemplateDraftAction;
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
            ->test(ListQuestionnaireTemplates::class)
            ->assertCanSeeTableRecords([$template])
            ->assertTableActionExists('edit', null, $template)
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

    public function test_admin_can_save_structured_draft_and_publish_it_from_full_page(): void
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
            ->test(EditQuestionnaireTemplate::class, ['record' => $template->key])
            ->fillForm([
                'name' => $template->name,
                ...$this->toTableEditorPayload($payload),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $template->refresh();

        $this->assertNotNull($template->draftVersion);
        $this->assertSame(2, $template->draftVersion->version);

        Livewire::actingAs($admin)
            ->test(EditQuestionnaireTemplate::class, ['record' => $template->key])
            ->call('publishDraft')
            ->assertHasNoFormErrors();

        $template->refresh();

        $this->assertSame(2, $template->publishedVersion->version);
        $this->assertSame('Выбери пол для локального теста', $template->publishedVersion->fields_payload[0]['prompts'][0]);
    }

    public function test_admin_can_save_structured_fields_draft_without_editing_json(): void
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
        $payload[0]['prompts'][0] = 'Выбери пол в новой форме';
        $payload[3]['options'][0]['label'] = '18-23';

        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
            $template,
            QuestionnaireTemplateResource::normalizeTableEditorPayload($this->toTableEditorPayload($payload)),
            $admin,
            'fields_payload',
        );

        $template->refresh();

        $this->assertNotNull($template->draftVersion);
        $this->assertSame(2, $template->draftVersion->version);
        $this->assertSame('Выбери пол в новой форме', $template->draftVersion->fields_payload[0]['prompts'][0]);
        $this->assertSame('18-23', $template->draftVersion->fields_payload[3]['options'][0]['label']);
    }

    public function test_editor_overview_shows_draft_badge_when_published_template_has_unpublished_draft(): void
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
        $payload[0]['prompts'][0] = 'Черновой вопрос про пол';

        app(SaveQuestionnaireTemplateDraftAction::class)->handle(
            $template,
            QuestionnaireTemplateResource::normalizeTableEditorPayload($this->toTableEditorPayload($payload)),
            $admin,
            'fields_payload',
        );

        $overview = (string) QuestionnaireTemplateResource::buildEditorOverview(
            $template->fresh(['publishedVersion', 'draftVersion', 'updater']),
        );

        $this->assertStringContainsString('Опубликована', $overview);
        $this->assertStringContainsString('Черновик', $overview);
    }

    public function test_editor_overview_renders_version_as_inline_badge(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->with(['publishedVersion', 'draftVersion', 'updater'])
            ->sole();

        $overview = (string) QuestionnaireTemplateResource::buildEditorOverview($template);

        $this->assertStringContainsString('<span class="qe-ver">ver v1</span>', $overview);
        $this->assertStringNotContainsString('qe-ver-label', $overview);
    }

    public function test_admin_can_create_questionnaire_with_structured_fields_draft(): void
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
            ->test(CreateQuestionnaireTemplate::class)
            ->fillForm([
                'key' => 'local_profile',
                'name' => 'Локальная анкета',
                ...$this->toTableEditorPayload($payload),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

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

    public function test_invalid_structured_create_does_not_leave_empty_questionnaire(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);

        Livewire::actingAs($admin)
            ->test(CreateQuestionnaireTemplate::class)
            ->fillForm([
                'key' => 'broken_local',
                'name' => 'Сломанная анкета',
                'fields_table' => [
                    [
                        'field_key' => 'first_name',
                        'label' => 'Имя',
                        'type' => 'text',
                        'target' => 'contact.first_name',
                        'required' => true,
                        'allow_skip' => false,
                        'max_attempts' => 3,
                    ],
                    [
                        'field_key' => 'nickname',
                        'label' => 'Ник',
                        'type' => 'text',
                        'target' => 'contact.first_name',
                        'required' => true,
                        'allow_skip' => false,
                        'max_attempts' => 3,
                    ],
                ],
                'prompts_table' => [
                    [
                        'field_key' => 'first_name',
                        'attempt' => 1,
                        'text' => 'Как тебя зовут?',
                    ],
                    [
                        'field_key' => 'nickname',
                        'attempt' => 1,
                        'text' => 'Как тебя называть?',
                    ],
                ],
                'options_table' => [],
            ])
            ->call('create')
            ->assertHasErrors(['fields_payload']);

        $this->assertDatabaseMissing('questionnaire_templates', [
            'key' => 'broken_local',
        ]);
    }

    public function test_editor_pages_are_full_page_routes(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $template = QuestionnaireTemplate::query()
            ->where('key', QuestionnaireTemplate::KEY_PROFILE)
            ->sole();

        $this->actingAs($admin)
            ->get('/admin/questionnaire-templates/create')
            ->assertOk()
            ->assertSee('Новая анкета');

        $this->actingAs($admin)
            ->get('/admin/questionnaire-templates/'.$template->key.'/edit')
            ->assertOk()
            ->assertSee('Профильная анкета')
            ->assertSee('Поля анкеты')
            ->assertDontSee('fields_payload_json');
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     * @return array{fields_table:list<array<string,mixed>>,prompts_table:list<array<string,mixed>>,options_table:list<array<string,mixed>>}
     */
    private function toTableEditorPayload(array $payload): array
    {
        return QuestionnaireTemplateResource::fieldsPayloadEditorFormData($payload);
    }
}
