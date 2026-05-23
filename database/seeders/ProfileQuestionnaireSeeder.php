<?php

namespace Database\Seeders;

use App\Models\Contact;
use App\Models\QuestionnaireTemplate;
use App\Models\QuestionnaireTemplateVersion;
use Illuminate\Database\Seeder;
use Locale;
use ResourceBundle;

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
                'type' => 'single_choice',
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
                'type' => 'dictionary_lookup',
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
                'field_key' => 'country',
                'label' => 'Страна',
                'type' => 'single_choice',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.country',
                'overwrite_contact' => true,
                'required_when' => '{{contact.country}} == ""',
                'prompts' => [
                    'В какой стране ты живёшь?',
                    'Выбери, пожалуйста, страну проживания',
                    'Подскажи страну, это нужно для правильной анкеты',
                ],
                'options' => $this->countryOptions(),
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
                    'Подскажи город, чтобы мы точнее подобрали условия',
                ],
            ],
            [
                'field_key' => 'region',
                'label' => 'Регион РФ',
                'type' => 'single_choice',
                'required' => true,
                'allow_skip' => false,
                'max_attempts' => 3,
                'target' => 'contact.region',
                'overwrite_contact' => true,
                'required_when' => '{{contact.country}} == "RU" and {{contact.region}} == ""',
                'prompts' => [
                    'Уточни регион проживания',
                    'Выбери, пожалуйста, регион РФ',
                    'Подскажи регион, чтобы мы корректно завершили анкету',
                ],
                'options' => $this->russianRegionOptions(),
            ],
            [
                'field_key' => 'age_range',
                'label' => 'Возраст',
                'type' => 'single_choice',
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
    private function countryOptions(): array
    {
        $options = [];

        $countries = null;

        if (class_exists(ResourceBundle::class)) {
            $bundle = ResourceBundle::create('ru', 'ICUDATA-region');
            $countries = $bundle instanceof ResourceBundle ? $bundle->get('Countries') : null;
        }

        if ($countries instanceof ResourceBundle) {
            foreach ($countries as $code => $name) {
                if (! is_string($code) || ! preg_match('/^[A-Z]{2}$/', $code)) {
                    continue;
                }

                $label = class_exists(Locale::class)
                    ? Locale::getDisplayRegion('und_'.$code, 'ru')
                    : null;

                if (! is_string($label) || trim($label) === '') {
                    $label = is_string($name) ? $name : $code;
                }

                $options[$code] = trim($label);
            }
        }

        if ($options === []) {
            $options = [
                'RU' => 'Россия',
                'KZ' => 'Казахстан',
                'BY' => 'Беларусь',
                'AM' => 'Армения',
            ];
        }

        $options = array_replace($options, [
            'RU' => 'Россия',
            'KZ' => 'Казахстан',
            'BY' => 'Беларусь',
            'AM' => 'Армения',
        ]);

        asort($options, SORT_NATURAL | SORT_FLAG_CASE);

        return array_map(
            static fn (string $code, string $label): array => [
                'value' => $code,
                'label' => $label,
            ],
            array_keys($options),
            array_values($options),
        );
    }

    /**
     * @return list<array{value:string,label:string}>
     */
    private function russianRegionOptions(): array
    {
        return array_map(
            static fn (string $value, string $label): array => [
                'value' => $value,
                'label' => $label,
            ],
            array_keys(Contact::russianRegionOptions()),
            array_values(Contact::russianRegionOptions()),
        );
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
