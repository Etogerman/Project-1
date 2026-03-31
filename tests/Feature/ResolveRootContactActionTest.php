<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Services\Contacts\BrokenContactMergeChainException;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResolveRootContactActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_the_same_contact_for_a_root_contact(): void
    {
        $contact = Contact::factory()->create();

        $resolved = app(ResolveRootContactAction::class)->handle($contact);

        $this->assertTrue($resolved->is($contact));
        $this->assertTrue($resolved->isRoot());
        $this->assertFalse($resolved->isMerged());
    }

    public function test_it_resolves_a_merged_contact_to_its_root(): void
    {
        $root = Contact::factory()->create();
        $merged = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
            'merge_reason' => 'phone_exact_match',
            'merge_trigger_phone' => '+79991234567',
        ]);

        $resolved = app(ResolveRootContactAction::class)->handle($merged->id);

        $this->assertTrue($resolved->is($root));
        $this->assertTrue($merged->fresh()->isMerged());
    }

    public function test_it_resolves_a_long_merge_chain_to_the_final_root(): void
    {
        $root = Contact::factory()->create();
        $middle = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);
        $leaf = Contact::factory()->create([
            'merged_into_contact_id' => $middle->id,
            'merged_at' => now(),
        ]);

        $resolved = app(ResolveRootContactAction::class)->handle($leaf);

        $this->assertTrue($resolved->is($root));
    }

    public function test_it_throws_for_a_cyclic_merge_chain(): void
    {
        $first = Contact::factory()->create();
        $second = Contact::factory()->create([
            'merged_into_contact_id' => $first->id,
            'merged_at' => now(),
        ]);

        $first->forceFill([
            'merged_into_contact_id' => $second->id,
            'merged_at' => now(),
        ])->save();

        $this->expectException(BrokenContactMergeChainException::class);

        app(ResolveRootContactAction::class)->handle($first);
    }
}
