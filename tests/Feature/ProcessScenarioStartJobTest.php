<?php

namespace Tests\Feature;

use App\Data\Scenarios\ScenarioInboundResult;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Services\Scenarios\ResolvedScenarioRuntime;
use App\Services\Scenarios\ScenarioRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Tests\TestCase;

class ProcessScenarioStartJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_jobs_for_same_dialog_share_without_overlapping_key(): void
    {
        $firstJob = new ProcessScenarioStartJob(1001, 77, 'warmup');
        $secondJob = new ProcessScenarioStartJob(1002, 77, 'warmup');

        $firstMiddleware = $firstJob->middleware()[0];
        $secondMiddleware = $secondJob->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $firstMiddleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $secondMiddleware);
        $this->assertSame('scenario-start:dialog:77', $this->overlapKey($firstMiddleware));
        $this->assertSame($this->overlapKey($firstMiddleware), $this->overlapKey($secondMiddleware));
    }

    public function test_legacy_compatible_job_without_dialog_id_uses_message_overlap_key(): void
    {
        $job = new ProcessScenarioStartJob(1001, null, 'warmup');

        $middleware = $job->middleware()[0];

        $this->assertInstanceOf(WithoutOverlapping::class, $middleware);
        $this->assertSame('scenario-start:message:1001', $this->overlapKey($middleware));
    }

    public function test_job_returns_without_creating_second_active_run_for_same_dialog(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-200',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-300',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'hello',
            'raw_payload' => [],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reaction',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $job = new ProcessScenarioStartJob($message->id, $dialog->id, 'warmup');

        $job->handle($this->mockScenarioRegistry());

        $this->assertDatabaseCount('scenario_runs', 1);
        $this->assertDatabaseHas('scenario_runs', [
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_legacy_compatible_job_restores_dialog_from_message_and_skips_existing_active_run(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-201',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-301',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'hello again',
            'raw_payload' => [],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reaction',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        $job = new ProcessScenarioStartJob($message->id, null, 'warmup');

        $job->handle($this->mockScenarioRegistry());

        $this->assertDatabaseCount('scenario_runs', 1);
        $this->assertDatabaseHas('scenario_runs', [
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
        ]);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_skips_when_snapshot_dialog_does_not_match_message_dialog(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-202',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-302',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'mismatch',
            'raw_payload' => [],
        ]);

        $registry = $this->createMock(ScenarioRegistry::class);
        $registry->expects($this->never())->method('makeRuntime');

        $job = new ProcessScenarioStartJob($message->id, $dialog->id + 100, 'warmup');

        $job->handle($registry);

        $this->assertDatabaseCount('scenario_runs', 0);
    }

    public function test_job_skips_when_dialog_is_blocked_by_user(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-203',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-303',
            'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
            'bot_subscription_changed_at' => now(),
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'hello blocked warmup',
            'raw_payload' => [],
        ]);

        $registry = $this->createMock(ScenarioRegistry::class);
        $registry->expects($this->never())->method('makeRuntime');

        $job = new ProcessScenarioStartJob($message->id, $dialog->id, 'warmup');

        $job->handle($registry);

        $this->assertDatabaseCount('scenario_runs', 0);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_skips_disabled_scenario_binding_without_starting_runtime(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-205',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-305',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'disabled warmup',
            'raw_payload' => [],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $registry = $this->createMock(ScenarioRegistry::class);
        $registry->expects($this->once())
            ->method('enabledForNewStarts')
            ->with('warmup')
            ->willReturn(false);
        $registry->expects($this->never())->method('makeRuntime');

        $job = new ProcessScenarioStartJob($message->id, $dialog->id, 'warmup');

        $job->handle($registry);

        $this->assertDatabaseCount('scenario_runs', 0);
        $this->assertDatabaseCount('messages', 1);
    }

    public function test_job_starts_scenario_even_when_contact_has_legacy_data_collection_state(): void
    {
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        $contact = Contact::factory()->create([
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_ACTIVE,
            'data_collection_current_field' => Contact::DATA_COLLECTION_FIELD_FIRST_NAME,
            'data_collection_started_at' => now()->subMinute(),
            'data_collection_current_field_started_at' => now()->subMinute(),
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-204',
        ]);
        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'telegram-chat-304',
        ]);
        $message = Message::factory()->create([
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'dialog_id' => $dialog->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'анкета',
            'raw_payload' => [],
        ]);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => 'warmup',
            'is_active' => true,
        ]);

        $job = new ProcessScenarioStartJob($message->id, $dialog->id, 'warmup');

        $job->handle($this->startingScenarioRegistry());

        $this->assertDatabaseHas('scenario_runs', [
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'started',
        ]);
    }

    private function overlapKey(WithoutOverlapping $middleware): string
    {
        return (new \ReflectionProperty($middleware, 'key'))->getValue($middleware);
    }

    private function mockScenarioRegistry(): ScenarioRegistry
    {
        $registry = $this->createMock(ScenarioRegistry::class);

        $registry->expects($this->once())
            ->method('enabledForNewStarts')
            ->with('warmup')
            ->willReturn(true);

        $registry->expects($this->once())
            ->method('makeRuntime')
            ->with('warmup')
            ->willReturn(new class implements ResolvedScenarioRuntime
            {
                public function code(): string
                {
                    return 'warmup';
                }

                public function shouldStart(Message $message): bool
                {
                    return true;
                }

                public function start(ScenarioRun $run, Message $message): void
                {
                    throw new \RuntimeException('Scenario start should not be called when active run already exists.');
                }

                public function supportsContactShareContinuation(ScenarioRun $run): bool
                {
                    return false;
                }

                public function supportsTelegramCallbackContinuation(ScenarioRun $run, string $callbackData): bool
                {
                    return false;
                }

                public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
                {
                    return new ScenarioInboundResult(
                        consumed: false,
                        status: ScenarioRun::STATUS_ACTIVE,
                        currentStep: null,
                        statePayload: [],
                        exitOutcome: null,
                    );
                }
            });

        return $registry;
    }

    private function startingScenarioRegistry(): ScenarioRegistry
    {
        $registry = $this->createMock(ScenarioRegistry::class);

        $registry->expects($this->once())
            ->method('enabledForNewStarts')
            ->with('warmup')
            ->willReturn(true);

        $registry->expects($this->once())
            ->method('makeRuntime')
            ->with('warmup')
            ->willReturn(new class implements ResolvedScenarioRuntime
            {
                public function code(): string
                {
                    return 'warmup';
                }

                public function shouldStart(Message $message): bool
                {
                    return true;
                }

                public function start(ScenarioRun $run, Message $message): void
                {
                    $run->forceFill([
                        'current_step' => 'started',
                    ])->save();
                }

                public function supportsContactShareContinuation(ScenarioRun $run): bool
                {
                    return false;
                }

                public function supportsTelegramCallbackContinuation(ScenarioRun $run, string $callbackData): bool
                {
                    return false;
                }

                public function handleInbound(ScenarioRun $run, Message $message): ScenarioInboundResult
                {
                    return new ScenarioInboundResult(
                        consumed: false,
                        status: ScenarioRun::STATUS_ACTIVE,
                        currentStep: null,
                        statePayload: [],
                        exitOutcome: null,
                    );
                }
            });

        return $registry;
    }
}
