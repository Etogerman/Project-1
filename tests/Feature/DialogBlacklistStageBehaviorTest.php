<?php

namespace Tests\Feature;

use App\Data\Dialogs\DialogInboxStatusData;
use App\Filament\Resources\Dialogs\DialogResource;
use App\Jobs\ProcessScenarioInboundJob;
use App\Jobs\ProcessScenarioStartJob;
use App\Models\AutoReplyRule;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\DialogStage;
use App\Models\Message;
use App\Models\MessageAttachment;
use App\Models\Scenario;
use App\Models\ScenarioChannelBinding;
use App\Models\ScenarioRun;
use App\Models\ScenarioVersion;
use App\Models\User;
use App\Services\Analytics\BuildAnalyticsOverviewAction;
use App\Services\Bitrix24\IsDialogReadyForBitrix24LiveBridgeAction;
use App\Services\Bitrix24\IsMessageReadyForBitrix24LiveExportAction;
use App\Services\Dialogs\BuildDialogNotificationStateAction;
use App\Services\Dialogs\DialogAutomationGate;
use App\Services\Dialogs\ResolveDialogInboxStatusAction;
use App\Services\Scenarios\DispatchDialogStageChangedScenarioAction;
use App\Services\Scenarios\ScenarioRegistry;
use App\Services\TelegramAccount\ClaimTelegramAccountMediaDownloadAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DialogBlacklistStageBehaviorTest extends TestCase
{
    use RefreshDatabase;

    public function test_blacklist_stage_dialog_is_not_requires_reply_and_does_not_notify(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
        ]);
        [$normalDialog] = $this->createDialogWithInbound();
        [$blacklistedDialog] = $this->createDialogWithInbound(blacklisted: true);

        $this->assertSame(
            DialogInboxStatusData::CODE_REQUIRES_REPLY,
            app(ResolveDialogInboxStatusAction::class)->handle($normalDialog->fresh())->code,
        );
        $this->assertSame(
            DialogInboxStatusData::CODE_NOT_REQUIRED,
            app(ResolveDialogInboxStatusAction::class)->handle($blacklistedDialog->fresh())->code,
        );

        $requiresReplyQuery = Dialog::query();
        DialogResource::applyInboxStatusFilter($requiresReplyQuery, DialogInboxStatusData::CODE_REQUIRES_REPLY);

        $this->assertSame([$normalDialog->id], $requiresReplyQuery->pluck('dialogs.id')->all());

        $notRequiredQuery = Dialog::query();
        DialogResource::applyInboxStatusFilter($notRequiredQuery, DialogInboxStatusData::CODE_NOT_REQUIRED);

        $this->assertSame([$blacklistedDialog->id], $notRequiredQuery->pluck('dialogs.id')->all());

        $notificationState = app(BuildDialogNotificationStateAction::class)->handle($admin);

        $this->assertSame(1, $notificationState['count']);
        $this->assertSame($normalDialog->id, $notificationState['items'][0]['dialog_id']);
    }

    public function test_blacklist_stage_dialogs_are_excluded_from_reply_analytics_and_problem_dialogs(): void
    {
        $now = now();
        [$normalDialog] = $this->createDialogWithInbound(receivedAt: $now->copy()->subHours(2));
        [$blacklistedDialog] = $this->createDialogWithInbound(blacklisted: true, receivedAt: $now->copy()->subHours(3));

        $overview = app(BuildAnalyticsOverviewAction::class)->handle($now->copy()->subDay(), $now, $now);
        $snapshotMetrics = collect($overview['snapshotMetrics'])->keyBy('key');

        $this->assertSame(1, $snapshotMetrics['requires_reply']['value']);
        $this->assertSame(1, $snapshotMetrics['requires_reply_overdue']['value']);
        $this->assertNotContains($blacklistedDialog->id, collect($overview['problemDialogs'])->pluck('id')->all());
        $this->assertContains($normalDialog->id, collect($overview['problemDialogs'])->pluck('id')->all());
        $this->assertNotContains(
            $blacklistedDialog->stage,
            collect($overview['stageRows'])->pluck('stage')->all(),
        );
    }

    public function test_active_scenario_run_is_cancelled_before_next_step_in_blacklist_stage(): void
    {
        [$dialog, $message] = $this->createDialogWithInbound(blacklisted: true);
        $run = ScenarioRun::query()->create([
            'dialog_id' => $dialog->id,
            'scenario_code' => 'warmup',
            'status' => ScenarioRun::STATUS_ACTIVE,
            'current_step' => 'awaiting_reply',
            'state_payload' => [],
            'started_at' => now()->subMinute(),
        ]);

        (new ProcessScenarioInboundJob($message->id, $run->id))->handle(app(ScenarioRegistry::class));

        $run->refresh();

        $this->assertSame(ScenarioRun::STATUS_CANCELLED, $run->status);
        $this->assertSame(DialogAutomationGate::REASON_BLACKLIST_STAGE, $run->exit_outcome);
    }

    public function test_stage_changed_scenario_is_not_dispatched_when_transition_touches_blacklist_stage(): void
    {
        Queue::fake();

        $blacklistStage = $this->blacklistStage();
        [$dialog] = $this->createDialogWithInbound();
        $channel = $dialog->channel()->firstOrFail();

        $enterScenario = $this->createStageChangedScenario($channel, 'blacklist_stage_enter', $blacklistStage->key);
        $leaveScenario = $this->createStageChangedScenario($channel, 'blacklist_stage_leave', Dialog::STAGE_TRANSFERRED_TO_MPL);

        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $enterScenario->code,
            'is_active' => true,
        ]);
        ScenarioChannelBinding::query()->create([
            'channel_id' => $channel->id,
            'scenario_code' => $leaveScenario->code,
            'is_active' => true,
        ]);

        $dispatcher = app(DispatchDialogStageChangedScenarioAction::class);

        $enterMessage = $this->createStageChangedMessage(
            $dialog,
            Dialog::STAGE_NEW_DIALOG,
            $blacklistStage->key,
        );
        $leaveMessage = $this->createStageChangedMessage(
            $dialog,
            $blacklistStage->key,
            Dialog::STAGE_TRANSFERRED_TO_MPL,
        );

        $this->assertFalse($dispatcher->handle($enterMessage));
        $this->assertFalse($dispatcher->handle($leaveMessage));

        Queue::assertNotPushed(ProcessScenarioStartJob::class);
        $this->assertNull(data_get($enterMessage->fresh()->raw_payload, 'scenario_stage_changed_dispatch'));
        $this->assertNull(data_get($leaveMessage->fresh()->raw_payload, 'scenario_stage_changed_dispatch'));
    }

    public function test_telegram_account_media_download_claim_marks_blacklist_media_metadata_only(): void
    {
        [$dialog, $message] = $this->createDialogWithInbound(blacklisted: true, accountChannel: true);
        $attachment = MessageAttachment::factory()->create([
            'message_id' => $message->id,
            'channel_id' => $dialog->channel_id,
            'provider' => MessageAttachment::PROVIDER_TELEGRAM_ACCOUNT,
            'provider_event_key' => $message->provider_event_key,
            'provider_attachment_key' => 'blacklist-file-1',
            'download_status' => MessageAttachment::DOWNLOAD_STATUS_PENDING_DOWNLOAD,
            'provider_file_id' => 'telegram-file-1',
            'media_kind' => MessageAttachment::MEDIA_KIND_DOCUMENT,
        ]);

        $claimed = app(ClaimTelegramAccountMediaDownloadAction::class)->handle($dialog->channel()->firstOrFail());

        $this->assertNull($claimed);
        $attachment->refresh();
        $this->assertSame(MessageAttachment::DOWNLOAD_STATUS_METADATA_ONLY, $attachment->download_status);
        $this->assertSame(DialogAutomationGate::REASON_BLACKLIST_STAGE, $attachment->safe_error_code);
        $this->assertNull($attachment->local_path);
    }

    public function test_bitrix24_live_export_readiness_is_blocked_before_bridge_check_for_blacklist_dialog(): void
    {
        [$dialog, $message] = $this->createDialogWithInbound(blacklisted: true);
        $dialog->contact()->update([
            'bitrix24_contact_id' => 'B24-BLACKLIST-1',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $bridgeReadiness = $this->createMock(IsDialogReadyForBitrix24LiveBridgeAction::class);
        $bridgeReadiness->expects($this->never())->method('handle');

        $action = new IsMessageReadyForBitrix24LiveExportAction(
            $bridgeReadiness,
            app(DialogAutomationGate::class),
        );

        $this->assertFalse($action->handle($message->fresh()));
    }

    /**
     * @return array{0: Dialog, 1: Message}
     */
    private function createDialogWithInbound(
        bool $blacklisted = false,
        mixed $receivedAt = null,
        bool $accountChannel = false,
    ): array {
        $channel = ($accountChannel ? Channel::factory()->account() : Channel::factory())->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'blacklist-user-'.fake()->unique()->numerify('###'),
        ]);
        $stage = $blacklisted
            ? $this->blacklistStage()
            : DialogStage::query()->where('key', Dialog::STAGE_NEW_DIALOG)->firstOrFail();
        $receivedAt ??= now();

        $dialog = Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'stage' => $stage->key,
            'stage_id' => $stage->id,
            'external_chat_id' => 'blacklist-chat-'.fake()->unique()->numerify('###'),
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $contact->id,
            'contact_identity_id' => $identity->id,
            'channel_id' => $channel->id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'external_chat_id' => $dialog->external_chat_id,
            'external_message_id' => 'blacklist-message-'.fake()->unique()->numerify('###'),
            'provider_event_key' => 'blacklist-event-'.fake()->unique()->numerify('###'),
            'text' => 'spam text',
            'received_at' => $receivedAt,
        ]);

        $dialog->forceFill([
            'last_message_at' => $receivedAt,
            'last_inbound_at' => $receivedAt,
            'last_message_id' => $message->id,
            'last_inbound_message_id' => $message->id,
            'last_message_preview' => $message->text,
            'last_inbound_message_preview' => $message->text,
        ])->save();

        return [$dialog->fresh(['contact', 'channel', 'dialogStage']), $message->fresh()];
    }

    private function createStageChangedScenario(Channel $channel, string $code, string $targetStage): Scenario
    {
        $scenario = Scenario::query()->create([
            'code' => $code,
            'name' => 'Stage changed '.$code,
            'is_active' => true,
            'is_archived' => false,
        ]);

        ScenarioVersion::query()->create([
            'scenario_id' => $scenario->id,
            'version_number' => 1,
            'status' => ScenarioVersion::STATUS_PUBLISHED,
            'schema_payload' => [
                'version' => 3,
                'builder_v3_runtime' => [
                    'schema_version' => 3,
                    'source_revision' => 'v3:blacklist-test',
                    'compiled_at' => now()->toISOString(),
                    'entrypoints' => [[
                        'block_id' => 'start',
                        'channel_ids' => [$channel->id],
                        'event' => 'stage_changed',
                        'stage_key' => $targetStage,
                        'match' => AutoReplyRule::MATCH_SCOPE_ANY_INBOUND,
                        'values' => [''],
                        'priority' => 10,
                    ]],
                    'blocks' => [
                        'start' => [
                            'id' => 'start',
                            'db_id' => 1,
                            'title' => 'Старт',
                            'message' => [
                                'text' => 'Автоответ на смену стадии',
                                'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
                            ],
                            'buttons' => null,
                            'default_target_block_id' => null,
                        ],
                    ],
                    'edges' => [],
                ],
            ],
        ]);

        return $scenario->fresh('publishedVersion');
    }

    private function createStageChangedMessage(Dialog $dialog, string $fromStage, string $toStage): Message
    {
        return Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_DIALOG_STATUS_CHANGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
            'external_chat_id' => $dialog->external_chat_id,
            'text' => 'Система изменила этап диалога',
            'text_format' => Message::TEXT_FORMAT_PLAIN_TEXT,
            'raw_payload' => [
                'event' => Message::SENT_BY_SYSTEM_CODE_DIALOG_STAGE_CHANGE,
                'from_stage' => $fromStage,
                'to_stage' => $toStage,
            ],
            'received_at' => now(),
        ]);
    }

    private function blacklistStage(): DialogStage
    {
        return DialogStage::query()->firstOrCreate(
            ['key' => 'blacklist'],
            [
                'name' => 'ЧС',
                'color' => DialogStage::COLOR_DANGER,
                'sort_order' => 900,
                'system_role' => null,
                'is_seeded' => false,
                'behavior_policy' => DialogStage::BEHAVIOR_POLICY_BLACKLIST,
            ],
        );
    }
}
