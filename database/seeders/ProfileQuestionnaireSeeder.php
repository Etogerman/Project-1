<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use Illuminate\Database\Seeder;

class ProfileQuestionnaireSeeder extends Seeder
{
    public function run(): void
    {
        $template = QuestionnaireTemplate::query()->firstOrCreate(
            ['key' => QuestionnaireTemplate::KEY_PROFILE],
            [
                'name' => 'Профильная анкета',
                'status' => QuestionnaireTemplate::STATUS_DRAFT,
            ],
        );

        if ($template->published_version_id !== null) {
            return;
        }

        $version = QuestionnaireTemplateVersion::query()->updateOrCreate(
            [
                'questionnaire_template_id' => $template->id,
                'version' => 1,
            ],
            [
                'status' => QuestionnaireTemplateVersion::STATUS_PUBLISHED,
                'fields_payload' => $this->profileFieldsPayload(),
                'published_at' => now(),
            ],
        );

        $template->forceFill([
            'name' => 'Профильная анкета',
            'status' => QuestionnaireTemplate::STATUS_PUBLISHED,
            'published_version_id' => $version->id,
        ])->save();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profileFieldsPayload(): array
    {
        return [
            [
                'field_key' => 'gender',
                'label' => 'Пол',
                'type' => 'choice',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.gender',
                'overwrite_contact' => true,
                'required_when' => '{{contact.gender}} == "" or {{contact.gender}} == "unknown"',
                'prompts' => [
                    'Укажи свой пол',
                    'Выбери, пожалуйста, пол',
                    'Подскажи пол, чтобы мы корректно продолжили анкету',
                ],
                'options' => [
                    ['value' => 'male', 'label' => 'Мужской'],
                    ['value' => 'female', 'label' => 'Женский'],
                ],
            ],
            [
                'field_key' => 'first_name',
                'label' => 'Имя',
                'type' => 'dictionary',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.first_name',
                'overwrite_contact' => true,
                'dictionary_key' => 'names',
                'required_when' => '{{contact.first_name}} == "" or {{contact.first_name_source}} == "auto" or {{contact.first_name_resolution_method}} == "messenger_profile"',
                'prompts' => [
                    'Как тебя зовут?',
                    'Напиши, пожалуйста, своё имя',
                    'Подскажи имя одним словом, чтобы мы могли продолжить',
                ],
            ],
            [
                'field_key' => 'city',
                'label' => 'Город',
                'type' => 'text',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.city',
                'overwrite_contact' => true,
                'required_when' => '{{contact.city}} == ""',
                'prompts' => [
                    'В каком городе ты живёшь?',
                    'Напиши, пожалуйста, город проживания',
                    'Подскажи город проживания, чтобы мы точнее подобрали условия',
                ],
            ],
            [
                'field_key' => 'age_range',
                'label' => 'Возраст',
                'type' => 'choice',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.age_range',
                'overwrite_contact' => true,
                'required_when' => '{{contact.age_range}} == ""',
                'prompts' => [
                    'Укажи свой возраст',
                    'Выбери, пожалуйста, возрастной диапазон',
                    'Подскажи возраст, это нужно для подбора условий',
                ],
                'options' => $this->ageRangeOptions(),
            ],
        ];
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function ageRangeOptions(): array
    {
        return array_map(
            static fn (string $value, string $label): array => [
                'value' => $value,
                'label' => $label,
            ],
            array_keys(Contact::ageRangeOptions()),
            array_values(Contact::ageRangeOptions()),
        );
    }
}
