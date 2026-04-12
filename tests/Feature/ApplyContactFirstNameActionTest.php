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
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_AUTO, $contact->first_name_source);
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
        $this->assertSame('Герман', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED, $contact->first_name_source);
        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
        ]);
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
        $this->assertSame('Ручное имя', $contact->first_name);
        $this->assertSame(Contact::FIRST_NAME_SOURCE_MANUAL, $contact->first_name_source);
        $this->assertDatabaseCount('contact_timeline_events', 0);
    }

    public function test_clear_resets_value_and_source_and_logs_timeline_event(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Герман',
            'first_name_source' => Contact::FIRST_NAME_SOURCE_CONTACT_CONFIRMED,
        ]);

        $result = app(ApplyContactFirstNameAction::class)->clear(
            $contact,
            ApplyContactFirstNameAction::REASON_MANUAL_EDIT,
        );

        $contact->refresh();

        $this->assertTrue($result->changed);
        $this->assertNull($contact->first_name);
        $this->assertNull($contact->first_name_source);
        $this->assertDatabaseHas('contact_timeline_events', [
            'contact_id' => $contact->id,
            'event_type' => ContactTimelineEvent::EVENT_FIRST_NAME_CHANGED,
        ]);
    }
}
