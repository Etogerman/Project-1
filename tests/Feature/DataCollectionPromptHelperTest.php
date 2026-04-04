<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Services\DataCollection\DataCollectionPromptHelper;
use Tests\TestCase;

class DataCollectionPromptHelperTest extends TestCase
{
    private DataCollectionPromptHelper $helper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->helper = new DataCollectionPromptHelper();

        config()->set('bots.data_collection.first_question', 'Как вас зовут?');
        config()->set('bots.data_collection.age_range.telegram_question', 'Укажите ваш возраст:');
        config()->set('bots.data_collection.age_range.max_question', 'Возраст для MAX:');
        config()->set('bots.data_collection.age_range.options', [
            [
                'value' => 'under_18',
                'label' => 'До 18 лет',
                'aliases' => ['до 18'],
            ],
            [
                'value' => '18_23',
                'label' => '18 - 23 года',
                'aliases' => ['18-23'],
            ],
            [
                'value' => '24_29',
                'label' => '24 - 29 лет',
                'aliases' => ['24-29', '24 29'],
            ],
            [
                'value' => '30_39',
                'label' => '30 - 39 лет',
                'aliases' => ['30-39'],
            ],
            [
                'value' => 'over_40',
                'label' => 'Больше 40 лет',
                'aliases' => ['40+'],
            ],
        ]);
        config()->set('bots.data_collection.russian_region.allowed_regions', [
            'Московская область',
            'Тверская область',
            'Владимирская область',
            'Ярославская область',
            'Смоленская область',
        ]);
        config()->set('bots.data_collection.russian_region_confirm.question_candidate_buttons', 'Уточните, пожалуйста, ваш регион проживания.');
        config()->set('bots.data_collection.russian_region_confirm.question_free_text', 'Уточните, пожалуйста, регион проживания текстом.');
        config()->set('bots.data_collection.russian_region_confirm.retry_candidate_buttons', 'Повторно выберите регион кнопкой.');
        config()->set('bots.data_collection.russian_region_confirm.retry_free_text', 'Повторно введите регион текстом.');
        config()->set('bots.data_collection.russian_region_confirm.skip_button_label', 'Пропустить');
    }

    public function test_it_resolves_age_range_from_value_label_and_alias(): void
    {
        $this->assertSame('24_29', $this->helper->resolveAgeRangeValue('24_29'));
        $this->assertSame('24_29', $this->helper->resolveAgeRangeValue('24 - 29 лет'));
        $this->assertSame('24_29', $this->helper->resolveAgeRangeValue('24-29'));
        $this->assertNull($this->helper->resolveAgeRangeValue('непонятно'));
    }

    public function test_it_builds_age_range_prompts_and_buttons_for_telegram_and_max(): void
    {
        $this->assertSame(
            'Укажите ваш возраст:',
            $this->helper->questionText(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, Channel::PLATFORM_TELEGRAM),
        );
        $this->assertSame(
            'Возраст для MAX:',
            $this->helper->questionText(Contact::DATA_COLLECTION_FIELD_AGE_RANGE, Channel::PLATFORM_MAX),
        );

        $telegramMarkup = $this->helper->telegramReplyMarkupForField(Contact::DATA_COLLECTION_FIELD_AGE_RANGE);
        $maxAttachments = $this->helper->maxAttachmentsForField(Contact::DATA_COLLECTION_FIELD_AGE_RANGE);

        $this->assertSame('До 18 лет', $telegramMarkup['inline_keyboard'][0][0]['text']);
        $this->assertSame('age_range:under_18', $telegramMarkup['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('24 - 29 лет', $telegramMarkup['inline_keyboard'][1][0]['text']);
        $this->assertSame('Больше 40 лет', $maxAttachments[0]['payload']['buttons'][2][0]['text']);
    }

    public function test_it_uses_candidate_buttons_mode_for_small_russian_region_set(): void
    {
        $contact = new Contact([
            'region' => null,
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => [
                'Тверская область',
                'Московская область',
            ],
        ]);

        $this->assertSame(
            DataCollectionPromptHelper::RUSSIAN_REGION_CONFIRM_MODE_CANDIDATE_BUTTONS,
            $this->helper->russianRegionConfirmMode($contact),
        );
        $this->assertTrue($this->helper->shouldAskRussianRegionConfirmation($contact));
        $this->assertSame(
            'Уточните, пожалуйста, ваш регион проживания.',
            $this->helper->russianRegionConfirmQuestionText($contact),
        );

        $telegramMarkup = $this->helper->telegramReplyMarkupForField(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact);
        $maxAttachments = $this->helper->maxAttachmentsForField(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact);

        $this->assertSame('Московская область', $telegramMarkup['inline_keyboard'][0][0]['text']);
        $this->assertSame('russian_region_confirm:1', $telegramMarkup['inline_keyboard'][0][0]['callback_data']);
        $this->assertSame('Пропустить', $telegramMarkup['inline_keyboard'][2][0]['text']);
        $this->assertSame('Тверская область', $maxAttachments[0]['payload']['buttons'][1][0]['text']);
    }

    public function test_it_uses_free_text_mode_for_large_russian_region_set(): void
    {
        $contact = new Contact([
            'region' => null,
            'region_status' => Contact::REGION_STATUS_AMBIGUOUS,
            'pending_region_candidates' => [
                'Тверская область',
                'Московская область',
                'Владимирская область',
                'Ярославская область',
                'Смоленская область',
            ],
        ]);

        $this->assertSame(
            DataCollectionPromptHelper::RUSSIAN_REGION_CONFIRM_MODE_FREE_TEXT,
            $this->helper->russianRegionConfirmMode($contact),
        );
        $this->assertSame(
            'Уточните, пожалуйста, регион проживания текстом.',
            $this->helper->russianRegionConfirmQuestionText($contact),
        );
        $this->assertSame(
            'Повторно введите регион текстом.',
            $this->helper->russianRegionConfirmRetryText($contact),
        );
        $this->assertNull(
            $this->helper->telegramReplyMarkupForField(Contact::DATA_COLLECTION_FIELD_RUSSIAN_REGION_CONFIRM, $contact),
        );
    }

    public function test_it_resolves_russian_region_confirm_from_callback_text_and_skip(): void
    {
        $contact = new Contact([
            'region' => null,
            'region_status' => Contact::REGION_STATUS_CLARIFICATION_PENDING,
            'pending_region_candidates' => [
                'Тверская область',
                'Московская область',
            ],
        ]);

        $this->assertSame(
            'Тверская область',
            $this->helper->resolveRussianRegionConfirmInput($contact, 'russian_region_confirm:2'),
        );
        $this->assertSame(
            'Московская область',
            $this->helper->resolveRussianRegionConfirmInput($contact, 'московская область'),
        );
        $this->assertSame(
            'skip',
            $this->helper->resolveRussianRegionConfirmInput($contact, 'russian_region_confirm:skip'),
        );
        $this->assertNull($this->helper->resolveRussianRegionConfirmInput($contact, 'непонятно'));
    }
}
