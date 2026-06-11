<?php

namespace Tests\Feature;

use App\Filament\Resources\AiProcessors\AiProcessorResource;
use App\Filament\Resources\DataDictionaryEntries\DataDictionaryEntryResource;
use App\Models\AiProcessor;
use App\Models\DataDictionaryEntry;
use App\Models\User;
use App\Policies\AiProcessorPolicy;
use App\Policies\DataDictionaryEntryPolicy;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class FilamentSensitiveSettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();
    }

    public function test_ai_processors_are_restricted_to_system_managers(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $processor = AiProcessor::query()->create([
            'name' => 'Gemini основной',
            'provider' => AiProcessor::PROVIDER_GEMINI,
            'model' => 'gemini-2.5-flash',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
            'credentials' => ['api_key' => 'secret'],
            'is_active' => true,
            'priority' => 10,
            'timeout_seconds' => 30,
            'temperature' => 0.2,
            'max_output_tokens' => 512,
            'thinking_budget' => 0,
        ]);

        $this->assertInstanceOf(AiProcessorPolicy::class, Gate::getPolicyFor(AiProcessor::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', AiProcessor::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', AiProcessor::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $processor));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $processor));

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', AiProcessor::class));
        $this->assertFalse(Gate::forUser($employee)->allows('create', AiProcessor::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $processor));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $processor));

        $this->actingAs($admin)
            ->get(AiProcessorResource::getUrl())
            ->assertOk()
            ->assertSee('ИИ-обработчики')
            ->assertSee('Добавить обработчик');

        $employeeResponse = $this->actingAs($employee)
            ->get(AiProcessorResource::getUrl());

        $this->assertContains($employeeResponse->getStatusCode(), [302, 403]);
    }

    public function test_names_dictionary_is_restricted_to_system_managers(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $entry = DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Саша',
            'result_value' => 'Александр',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(DataDictionaryEntryPolicy::class, Gate::getPolicyFor(DataDictionaryEntry::class));
        $this->assertTrue(Gate::forUser($admin)->allows('viewAny', DataDictionaryEntry::class));
        $this->assertTrue(Gate::forUser($admin)->allows('create', DataDictionaryEntry::class));
        $this->assertTrue(Gate::forUser($admin)->allows('update', $entry));
        $this->assertTrue(Gate::forUser($admin)->allows('delete', $entry));
        $this->assertTrue(Gate::forUser($admin)->allows('deleteAny', DataDictionaryEntry::class));

        $this->assertFalse(Gate::forUser($employee)->allows('viewAny', DataDictionaryEntry::class));
        $this->assertFalse(Gate::forUser($employee)->allows('create', DataDictionaryEntry::class));
        $this->assertFalse(Gate::forUser($employee)->allows('update', $entry));
        $this->assertFalse(Gate::forUser($employee)->allows('delete', $entry));
        $this->assertFalse(Gate::forUser($employee)->allows('deleteAny', DataDictionaryEntry::class));

        $this->actingAs($admin)
            ->get(DataDictionaryEntryResource::getUrl())
            ->assertOk()
            ->assertSee('Имена')
            ->assertSee('Экспорт CSV')
            ->assertSee('Импорт CSV')
            ->assertSee('Добавить имя');

        $employeeResponse = $this->actingAs($employee)
            ->get(DataDictionaryEntryResource::getUrl());

        $this->assertContains($employeeResponse->getStatusCode(), [302, 403]);
    }
}
