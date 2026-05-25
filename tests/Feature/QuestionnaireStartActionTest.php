<?php

namespace Tests\Feature;

use App\Data\Questionnaires\QuestionnaireStartResult;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactQuestionnaireAnswer;
use App\Models\ContactQuestionnaireAttempt;
use App\Models\ContactQuestionnaireRun;
use App\Models\DataDictionaryEntry;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Questionnaires\HandleContactQuestionnaireAnswerAction;
use App\Services\Questionnaires\StartOrContinueContactQuestionnaireAction;
use Database\Seeders\ProfileQuestionnaireSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuestionnaireStartActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_questionnaire_starts_and_waits_for_first_required_field(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        [$contact, $message] = $this->createIncomingMessage();

        $result = app(StartOrContinueContactQuestionnaireAction::class)->handle($message);

        $run = ContactQuestionnaireRun::query()->where('contact_id', $contact->id)->sole();
        $answer = ContactQuestionnaireAnswer::query()->where('questionnaire_run_id', $run->id)->sole();

        $this->assertSame(QuestionnaireStartResult::OUTCOME_WAITING, $result->outcome);
        $this->assertSame('gender', $result->currentFieldKey);
        $this->assertSame('Укажи свой пол', $result->promptText);
        $this->assertSame([
            ['value' => 'male', 'label' => 'Мужской'],
            ['value' => 'female', 'label' => 'Женский'],
        ], $result->options);

        $this->assertSame(ContactQuestionnaireRun::STATUS_AWAITING_ANSWER, $run->status);
        $this->assertSame('gender', $run->current_field_key);
        $this->assertSame($message->dialog_id, $run->started_dialog_id);
        $this->assertSame($message->dialog_id, $run->last_dialog_id);
        $this->assertSame(ContactQuestionnaireAnswer::STATUS_ASKED, $answer->status);
        $this->assertSame('gender', $answer->field_key);
        $this->assertSame('contact.gender', $answer->target);
    }

    public function test_profile_questionnaire_completes_when_contact_already_has_required_fields(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        [$contact, $message] = $this->createIncomingMessage([
            'gender' => 'male',
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'first_name_resolution_method' => null,
            'country' => 'RU',
            'city' => 'Москва',
            'region' => 'Московская область',
            'age_range' => '30_39',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
        ]);

        $result = app(StartOrContinueContactQuestionnaireAction::class)->handle($message);

        $run = ContactQuestionnaireRun::query()->where('contact_id', $contact->id)->sole();

        $this->assertSame(QuestionnaireStartResult::OUTCOME_COMPLETED, $result->outcome);
        $this->assertSame(ContactQuestionnaireRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_field_key);
        $this->assertNotNull($run->completed_at);

        $contact->refresh();
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);
        $this->assertNull($contact->data_collection_current_field);
        $this->assertNotNull($contact->data_collection_completed_at);
    }

    public function test_profile_questionnaire_accepts_all_mvp_field_types_until_completed(): void
    {
        config()->set('bots.data_collection.profile_collection_engine', 'questionnaires');

        $this->seed(ProfileQuestionnaireSeeder::class);
        $this->seedNameDictionary();

        [$contact, $message] = $this->createIncomingMessage([
            'gender' => 'unknown',
            'first_name' => null,
            'country' => null,
            'city' => null,
            'region' => null,
            'age_range' => null,
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
        ]);

        $startResult = app(StartOrContinueContactQuestionnaireAction::class)->handle($message);

        $this->assertSame(QuestionnaireStartResult::OUTCOME_WAITING, $startResult->outcome);
        $this->assertSame('gender', $startResult->currentFieldKey);

        $handler = app(HandleContactQuestionnaireAnswerAction::class);

        $this->answerQuestionnaire($handler, $message, 'Мужской', 'first_name');
        $this->answerQuestionnaire($handler, $message, 'Коля', 'city');
        $this->answerQuestionnaire($handler, $message, 'анкета', 'city');

        $contact->refresh();
        $this->assertNull($contact->city);
        $this->assertNull($contact->country);
        $this->assertNull($contact->region);

        $this->answerQuestionnaire($handler, $message, 'Мурманск', 'age_range');
        $this->answerQuestionnaire($handler, $message, '30 - 39 лет', null);

        $run = ContactQuestionnaireRun::query()->where('contact_id', $contact->id)->sole();

        $this->assertSame(ContactQuestionnaireRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->current_field_key);
        $this->assertNotNull($run->completed_at);

        $contact->refresh();
        $this->assertSame('male', $contact->gender);
        $this->assertSame('Николай', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP, $contact->first_name_resolution_method);
        $this->assertSame('RU', $contact->country);
        $this->assertSame('Мурманск', $contact->city);
        $this->assertSame('Мурманская область', $contact->region);
        $this->assertSame(Contact::REGION_STATUS_RESOLVED, $contact->region_status);
        $this->assertSame(Contact::REGION_SOURCE_AI, $contact->region_source);
        $this->assertSame('30_39', $contact->age_range);
        $this->assertSame(Contact::DATA_COLLECTION_STATUS_COMPLETED, $contact->data_collection_status);

        $this->assertSame(4, ContactQuestionnaireAnswer::query()
            ->where('questionnaire_run_id', $run->id)
            ->where('status', ContactQuestionnaireAnswer::STATUS_FILLED)
            ->count());
        $this->assertSame(4, ContactQuestionnaireAttempt::query()
            ->where('questionnaire_run_id', $run->id)
            ->where('status', ContactQuestionnaireAttempt::STATUS_ACCEPTED)
            ->count());
        $this->assertSame(1, ContactQuestionnaireAttempt::query()
            ->where('questionnaire_run_id', $run->id)
            ->where('field_key', 'city')
            ->where('status', ContactQuestionnaireAttempt::STATUS_REJECTED)
            ->where('error', 'unknown city')
            ->count());
    }

    public function test_profile_questionnaire_start_resumes_paused_run(): void
    {
        $this->seed(ProfileQuestionnaireSeeder::class);

        [$contact, $message] = $this->createIncomingMessage();

        app(StartOrContinueContactQuestionnaireAction::class)->handle($message);

        $run = ContactQuestionnaireRun::query()->where('contact_id', $contact->id)->sole();
        $run->forceFill([
            'status' => ContactQuestionnaireRun::STATUS_PAUSED,
            'awaiting_block_id' => null,
            'scenario_run_id' => null,
        ])->save();

        $result = app(StartOrContinueContactQuestionnaireAction::class)->handle($message);

        $run->refresh();

        $this->assertSame(QuestionnaireStartResult::OUTCOME_WAITING, $result->outcome);
        $this->assertSame((int) $run->id, $result->runId);
        $this->assertSame('gender', $result->currentFieldKey);
        $this->assertSame(ContactQuestionnaireRun::STATUS_AWAITING_ANSWER, $run->status);
        $this->assertSame('gender', $run->current_field_key);
        $this->assertSame(1, ContactQuestionnaireRun::query()->where('contact_id', $contact->id)->count());
    }

    /**
     * @param  array<string, mixed>  $contactOverrides
     * @return array{0: Contact, 1: Message}
     */
    private function createIncomingMessage(array $contactOverrides = []): array
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create(array_merge([
            'is_auto_reply_enabled' => true,
        ], $contactOverrides));

        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'questionnaire-user-1',
        ]);

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'questionnaire-chat-1',
        ]);

        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'старт анкеты',
        ]);

        return [$contact, $message->fresh(['contact', 'dialog', 'channel'])];
    }

    private function createReplyMessage(Message $baseMessage, string $text): Message
    {
        return Message::factory()->create([
            'contact_id' => $baseMessage->contact_id,
            'contact_identity_id' => $baseMessage->contact_identity_id,
            'channel_id' => $baseMessage->channel_id,
            'dialog_id' => $baseMessage->dialog_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $baseMessage->external_chat_id,
            'text' => $text,
        ])->fresh(['contact', 'dialog', 'channel']);
    }

    private function answerQuestionnaire(
        HandleContactQuestionnaireAnswerAction $handler,
        Message $baseMessage,
        string $text,
        ?string $expectedNextField,
    ): void {
        $reply = $this->createReplyMessage($baseMessage, $text);

        $this->assertTrue($handler->handle($reply));

        $run = ContactQuestionnaireRun::query()->where('contact_id', $baseMessage->contact_id)->sole();

        $this->assertSame($expectedNextField, $run->current_field_key);
    }

    private function seedNameDictionary(): void
    {
        DataDictionaryEntry::query()->create([
            'dictionary_key' => DataDictionaryEntry::DICTIONARY_NAMES,
            'lookup_value' => 'Коля',
            'result_value' => 'Николай',
            'gender' => DataDictionaryEntry::GENDER_MALE,
            'language' => DataDictionaryEntry::LANGUAGE_RU,
            'variant_type' => DataDictionaryEntry::VARIANT_TYPE_SHORT,
            'auto_apply' => true,
            'is_active' => true,
        ]);
    }
}
