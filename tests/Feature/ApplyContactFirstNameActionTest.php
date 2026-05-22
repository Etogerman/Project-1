<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ContactTimelineEvent;
use App\Services\Contacts\ApplyContactFirstNameAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyContactFirstNameActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_initial_set_saves_value_and_source_without_timeline_event(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'first_name_source' => null,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            '  Герман  ',
            Contact::FIRST_NAME_SOURCE_AUTO,
            ApplyContactFirstNameAction::REASON_AUTO_INBOUND,
        );

        $contact->refresh();

        $this->assertTrue($result->changed);
        $this->assertTrue($result->bitrix24RelevantChanged);
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertNull($contact->first_name_resolution_method);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_initial_set_can_save_resolution_method(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => null,
            'first_name_source' => null,
            'first_name_resolution_method' => null,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_AUTO,
            ApplyContactFirstNameAction::REASON_AUTO_INBOUND,
            Contact::FIRST_NAME_RESOLUTION_METHOD_MESSENGER_PROFILE,
        );

        $contact->refresh();

        $this->assertTrue($result->changed);
        $this->assertTrue($result->bitrix24RelevantChanged);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_MESSENGER_PROFILE, $contact->first_name_resolution_method);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_handle_is_noop_when_value_and_source_are_unchanged(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_AUTO,
            ApplyContactFirstNameAction::REASON_AUTO_INBOUND,
        );

        $this->assertFalse($result->changed);
        $this->assertFalse($result->bitrix24RelevantChanged);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_empty_string_does_not_write_first_name(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            '   ',
            Contact::FIRST_NAME_SOURCE_MANUAL,
            ApplyContactFirstNameAction::REASON_MANUAL_EDIT,
        );

        $contact->refresh();

        $this->assertFalse($result->changed);
        $this->assertFalse($result->bitrix24RelevantChanged);
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_contact_confirmed_can_overwrite_auto_and_logs_timeline_event(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Имя из профиля',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
        );

        $contact->refresh();

        $this->assertTrue($result->changed);
        $this->assertTrue($result->bitrix24RelevantChanged);
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertNull($contact->first_name_resolution_method);
        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
        ]);
    }

    public function test_old_call_without_resolution_method_clears_previous_method_when_name_changes(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Имя из профиля',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_AUTO,
            'first_name_resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_MESSENGER_PROFILE,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
        );

        $contact->refresh();
        $event = ContactTimelineEvent::query()->where('contact_id', $contact->id)->first();

        $this->assertTrue($result->changed);
        $this->assertTrue($result->bitrix24RelevantChanged);
        $this->assertNull($contact->first_name_resolution_method);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_MESSENGER_PROFILE, data_get($event?->payload, 'previous_resolution_method'));
        $this->assertNull(data_get($event?->payload, 'new_resolution_method'));
    }

    public function test_old_call_without_resolution_method_keeps_method_when_name_and_source_are_unchanged(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'first_name_resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
        );

        $contact->refresh();

        $this->assertFalse($result->changed);
        $this->assertFalse($result->bitrix24RelevantChanged);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_DICTIONARY_LOOKUP, $contact->first_name_resolution_method);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_resolution_method_only_change_logs_history_without_bitrix24_relevant_change(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'first_name_resolution_method' => null,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Герман',
            Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            ApplyContactFirstNameAction::REASON_SCENARIO_CONFIRMED,
            Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS,
        );

        $contact->refresh();
        $event = ContactTimelineEvent::query()->where('contact_id', $contact->id)->first();

        $this->assertTrue($result->changed);
        $this->assertFalse($result->bitrix24RelevantChanged);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS, $contact->first_name_resolution_method);
        $this->assertSame(Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS, data_get($event?->payload, 'new_resolution_method'));
    }

    public function test_auto_cannot_overwrite_manual_name(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Ручное имя',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_MANUAL,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->handle(
            $contact,
            'Имя из профиля',
            Contact::FIRST_NAME_SOURCE_AUTO,
            ApplyContactFirstNameAction::REASON_AUTO_INBOUND,
        );

        $contact->refresh();

        $this->assertFalse($result->changed);
        $this->assertFalse($result->bitrix24RelevantChanged);
        $this->assertSame('Ручное имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $contact->first_name_source);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_clear_resets_value_and_source_and_logs_timeline_event(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
            'first_name_resolution_method' => Contact::FIRST_NAME_RESOLUTION_METHOD_AI_ANALYSIS,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->clear(
            $contact,
            ApplyContactFirstNameAction::REASON_MANUAL_EDIT,
        );

        $contact->refresh();

        $this->assertTrue($result->changed);
        $this->assertTrue($result->bitrix24RelevantChanged);
        $this->assertNull($contact->first_name);
        $this->assertNull($contact->first_name_source);
        $this->assertNull($contact->first_name_resolution_method);
        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
        ]);
    }
}
