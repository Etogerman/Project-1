<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\User;
use App\Services\Contacts\ClaimContactAction;
use App\Services\Contacts\ReleaseContactAssignmentAction;
use App\Services\Contacts\SetContactAssigneeAction;
use App\Services\Contacts\SetContactAutoReplyEnabledAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactRootResolutionActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_claim_contact_action_claims_root_when_merged_contact_is_passed(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'assigned_user_id' => null,
        ]);

        $claimed = app(ClaimContactAction::class)->handle($merged, $admin);

        $this->assertSame($root->id, $claimed->id);
        $this->assertSame($admin->id, $root->fresh()->assigned_user_id);
        $this->assertNull($merged->fresh()->assigned_user_id);
    }

    public function test_release_contact_assignment_action_releases_root_when_merged_contact_is_passed(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => $admin->id,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'assigned_user_id' => null,
        ]);

        $released = app(ReleaseContactAssignmentAction::class)->handle($merged, $admin);

        $this->assertSame($root->id, $released->id);
        $this->assertNull($root->fresh()->assigned_user_id);
        $this->assertNull($merged->fresh()->assigned_user_id);
    }

    public function test_set_contact_assignee_action_updates_root_when_merged_contact_is_passed(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'assigned_user_id' => null,
        ]);

        $updated = app(SetContactAssigneeAction::class)->handle($merged, $actor, $assignee->id);

        $this->assertSame($root->id, $updated->id);
        $this->assertSame($assignee->id, $root->fresh()->assigned_user_id);
        $this->assertNull($merged->fresh()->assigned_user_id);
    }

    public function test_claim_contact_action_allows_active_employee_actor(): void
    {
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);

        $claimed = app(ClaimContactAction::class)->handle($root, $employee);

        $this->assertSame($root->id, $claimed->id);
        $this->assertSame($employee->id, $root->fresh()->assigned_user_id);
    }

    public function test_release_contact_assignment_action_allows_employee_to_clear_foreign_assignment(): void
    {
        $owner = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        $employee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => $owner->id,
        ]);

        $released = app(ReleaseContactAssignmentAction::class)->handle($root, $employee);

        $this->assertSame($root->id, $released->id);
        $this->assertNull($root->fresh()->assigned_user_id);
    }

    public function test_set_contact_assignee_action_allows_employee_actor_and_employee_assignee(): void
    {
        $actor = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $assignee = User::factory()->create([
            'is_active' => true,
            'is_admin' => false,
        ]);
        $root = Contact::factory()->create([
            'assigned_user_id' => null,
        ]);

        $updated = app(SetContactAssigneeAction::class)->handle($root, $actor, $assignee->id);

        $this->assertSame($root->id, $updated->id);
        $this->assertSame($assignee->id, $root->fresh()->assigned_user_id);
    }

    public function test_set_contact_auto_reply_enabled_action_updates_root_when_merged_contact_is_passed(): void
    {
        $root = Contact::factory()->create([
            'is_auto_reply_enabled' => true,
        ]);
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'is_auto_reply_enabled' => true,
        ]);

        $updated = app(SetContactAutoReplyEnabledAction::class)->handle($merged, false);

        $this->assertSame($root->id, $updated->id);
        $this->assertFalse($root->fresh()->is_auto_reply_enabled);
        $this->assertTrue($merged->fresh()->is_auto_reply_enabled);
    }
}
