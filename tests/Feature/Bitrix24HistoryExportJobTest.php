<?php

namespace Tests\Feature;

use App\Jobs\SyncChatHistoryToBitrix24Job;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24MessageExport;
use App\Models\Bitrix24SyncLog;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\ContactPhoneNumber;
use App\Models\Message;
use App\Services\Bitrix24\IsContactReadyForBitrix24HistoryExportAction;
use App\Services\Bitrix24\LogBitrix24ApiCallAction;
use App\Services\Bitrix24\SyncChatHistoryToBitrix24Action;
use App\Services\Contacts\ResolveRootContactAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24HistoryExportJobTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('app.timezone', 'Europe/Moscow');
        config()->set('bitrix24.application.client_id', 'local.app');
        config()->set('bitrix24.application.client_secret', 'local.secret');
        config()->set('bitrix24.features.timeline_history_import_enabled', true);
        config()->set('bitrix24.http.retry_sleep_milliseconds', 0);
    }

    public function test_history_export_noop_marks_contact_as_synced_when_no_candidates_exist(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);

        $this->runHistoryExportJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, $contact->bitrix24_history_sync_status);
        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertNotNull($contact->bitrix24_history_last_synced_at);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'history_export_noop',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_history_export_without_deal_exports_only_contact_timeline(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $message = $this->makeMessage($contact, $identity, 'Только контакт');

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::response([
                'result' => 901,
            ], 200),
        ]);

        $this->runHistoryExportJob($contact);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($contact, $message): bool {
            return $request->url() === 'https://client-endpoint.example/rest/crm.timeline.comment.add.json'
                && $request['fields']['ENTITY_TYPE'] === 'contact'
                && $request['fields']['ENTITY_ID'] === $contact->bitrix24_contact_id
                && str_contains((string) ($request['fields']['COMMENT'] ?? ''), $message->text);
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '901',
        ]);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'history_export_chunk_sent_deal',
            'entity_type' => 'deal',
        ]);
    }

    public function test_history_export_uses_current_runtime_profile_connection_when_multiple_active_connections_exist(): void
    {
        $this->makeActiveConnection([
            'client_endpoint' => 'https://selected-client.example/rest/',
            'server_endpoint' => 'https://selected-server.example/rest/',
        ]);
        $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'member_id' => 'member-2',
                'application_token' => 'application-token-2',
                'client_endpoint' => 'https://ignored-client.example/rest/',
                'server_endpoint' => 'https://ignored-server.example/rest/',
            ],
            profileOverrides: [
                'profile_key' => 'dev-alex',
                'display_name' => 'Dev Alex',
                'application_code' => 'local.app.code.dev-alex',
                'callback_base_url' => 'https://other.example.com',
            ],
            useForCurrentRuntime: false,
        );

        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();
        $message = $this->makeMessage($contact, $identity, 'Только current runtime');

        Http::fake([
            'https://selected-client.example/rest/crm.timeline.comment.add.json' => Http::response([
                'result' => 901,
            ], 200),
        ]);

        $this->runHistoryExportJob($contact);

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '901',
        ]);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://selected-client.example/rest/'));
        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://ignored-client.example/rest/'));
    }

    public function test_history_export_with_deal_copies_chunk_to_contact_and_deal_timelines(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
            'bitrix24_deal_id' => '888',
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $message = $this->makeMessage($contact, $identity, 'Контакт и сделка');

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::sequence()
                ->push(['result' => 960], 200)
                ->push(['result' => 961], 200),
        ]);

        $this->runHistoryExportJob($contact);

        $requests = Http::recorded()
            ->map(static fn (array $pair): array => [
                'entity_type' => $pair[0]['fields']['ENTITY_TYPE'] ?? null,
                'entity_id' => $pair[0]['fields']['ENTITY_ID'] ?? null,
                'comment' => (string) ($pair[0]['fields']['COMMENT'] ?? ''),
            ])
            ->values();

        $this->assertCount(2, $requests);
        $this->assertSame('contact', $requests[0]['entity_type']);
        $this->assertSame($contact->bitrix24_contact_id, $requests[0]['entity_id']);
        $this->assertSame('deal', $requests[1]['entity_type']);
        $this->assertSame('888', $requests[1]['entity_id']);
        $this->assertTrue(str_contains($requests[0]['comment'], $message->text));
        $this->assertTrue(str_contains($requests[1]['comment'], $message->text));

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '960',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'history_export_chunk_sent_deal',
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'entity_type' => 'deal',
            'entity_id' => '888',
        ]);
    }

    public function test_history_export_deal_copy_failure_does_not_break_primary_contact_export(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
            'bitrix24_deal_id' => '888',
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $message = $this->makeMessage($contact, $identity, 'Сделка может упасть');

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::sequence()
                ->push(['result' => 970], 200)
                ->push(['error' => 'ERROR_CORE', 'error_description' => 'Deal copy failed'], 200),
        ]);

        $this->runHistoryExportJob($contact);

        $contact->refresh();

        Http::assertSentCount(2);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, $contact->bitrix24_history_sync_status);
        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $message->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '970',
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'history_export_chunk_failed_deal',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'deal',
            'entity_id' => '888',
        ]);
    }

    public function test_history_export_orders_messages_by_message_chronology_and_includes_merged_children(): void
    {
        $this->makeActiveConnection();
        $root = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $child = Contact::factory()->create([
            'merged_into_contact_id' => $root->id,
            'merged_at' => now(),
        ]);

        $rootIdentity = $root->primaryIdentity()->firstOrFail();
        $childChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
        ]);
        $childIdentity = ContactIdentity::factory()->create([
            'contact_id' => $child->id,
            'channel_id' => $childChannel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => 'max-child-history',
        ]);

        $sameMoment = Carbon::parse('2026-04-01 10:00:00', 'Europe/Moscow');

        $first = Message::factory()->create([
            'contact_identity_id' => $rootIdentity->id,
            'contact_id' => $root->id,
            'channel_id' => $rootIdentity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Первое сообщение',
            'received_at' => $sameMoment->copy()->subMinute(),
            'created_at' => $sameMoment->copy()->subMinute(),
        ]);

        $second = Message::factory()->create([
            'contact_identity_id' => $childIdentity->id,
            'contact_id' => $child->id,
            'channel_id' => $childIdentity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_MANUAL_REPLY,
            'sent_by_type' => Message::SENT_BY_TYPE_OPERATOR,
            'text' => 'Ответ оператора',
            'received_at' => $sameMoment,
            'created_at' => $sameMoment,
        ]);

        $third = Message::factory()->create([
            'contact_identity_id' => $rootIdentity->id,
            'contact_id' => $root->id,
            'channel_id' => $rootIdentity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_CONTACT_SHARE,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => null,
            'received_at' => $sameMoment,
            'created_at' => $sameMoment,
        ]);

        $fourth = Message::factory()->create([
            'contact_identity_id' => $rootIdentity->id,
            'contact_id' => $root->id,
            'channel_id' => $rootIdentity->channel_id,
            'direction' => Message::DIRECTION_OUTBOUND,
            'message_kind' => Message::KIND_OUTBOUND_SCENARIO_MESSAGE,
            'sent_by_type' => Message::SENT_BY_TYPE_SYSTEM,
            'sent_by_system_code' => Message::SENT_BY_SYSTEM_CODE_SCENARIO_BUILDER_START_CONDITION,
            'text' => 'Ответ V3-конструктора',
            'received_at' => $sameMoment->copy()->addMinute(),
            'created_at' => $sameMoment->copy()->addMinute(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::response([
                'result' => 910,
            ], 200),
        ]);

        $this->runHistoryExportJob($root);

        $root->refresh();

        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, $root->bitrix24_history_sync_status);
        $this->assertFalse($root->bitrix24_history_sync_pending);

        Http::assertSent(function ($request) use ($first, $second, $third, $fourth): bool {
            if ($request->url() !== 'https://client-endpoint.example/rest/crm.timeline.comment.add.json') {
                return false;
            }

            $comment = (string) ($request['fields']['COMMENT'] ?? '');
            $firstPosition = mb_strpos($comment, $first->text);
            $secondPosition = mb_strpos($comment, $second->text);
            $thirdPosition = mb_strpos($comment, 'Клиент поделился номером телефона');
            $fourthPosition = mb_strpos($comment, $fourth->text);

            return $request['fields']['ENTITY_TYPE'] === 'contact'
                && $firstPosition !== false
                && $secondPosition !== false
                && $thirdPosition !== false
                && $fourthPosition !== false
                && $firstPosition < $secondPosition
                && $secondPosition < $thirdPosition
                && $thirdPosition < $fourthPosition
                && str_contains($comment, 'Клиент / Telegram')
                && str_contains($comment, 'Оператор / MAX');
        });

        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $first->id,
            'contact_id' => $root->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '910',
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $second->id,
            'contact_id' => $root->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $third->id,
            'contact_id' => $root->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $fourth->id,
            'contact_id' => $root->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
    }

    public function test_history_export_skips_messages_already_exported_in_history_or_live_modes(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $historyExported = $this->makeMessage($contact, $identity, 'Уже в history');
        $liveExported = $this->makeMessage($contact, $identity, 'Уже в live');
        $candidate = $this->makeMessage($contact, $identity, 'Нужно экспортировать');

        Bitrix24MessageExport::query()->create([
            'message_id' => $historyExported->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'exported_at' => now()->subMinute(),
        ]);
        Bitrix24MessageExport::query()->create([
            'message_id' => $liveExported->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_LIVE,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'exported_at' => now()->subMinute(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::response([
                'result' => 920,
            ], 200),
        ]);

        $this->runHistoryExportJob($contact);

        Http::assertSent(function ($request) use ($historyExported, $liveExported, $candidate): bool {
            $comment = (string) ($request['fields']['COMMENT'] ?? '');

            return str_contains($comment, $candidate->text)
                && ! str_contains($comment, $historyExported->text)
                && ! str_contains($comment, $liveExported->text);
        });

        $historyExportedRow = Bitrix24MessageExport::query()
            ->where('message_id', $historyExported->id)
            ->where('export_mode', Bitrix24MessageExport::MODE_HISTORY)
            ->firstOrFail();

        $this->assertSame(Bitrix24MessageExport::STATUS_EXPORTED, $historyExportedRow->export_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $candidate->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
    }

    public function test_history_export_retries_failed_and_pending_rows_and_stops_after_failed_chunk(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $first = $this->makeLongMessage($contact, $identity, 'Первая', 5000);
        $second = $this->makeLongMessage($contact, $identity, 'Вторая', 5000);
        $third = $this->makeLongMessage($contact, $identity, 'Третья', 5000);

        Bitrix24MessageExport::query()->create([
            'message_id' => $first->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'failed_at' => now()->subMinute(),
            'failure_reason' => 'Old error',
        ]);
        Bitrix24MessageExport::query()->create([
            'message_id' => $second->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_PENDING,
            'batch_uuid' => fake()->uuid(),
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::sequence()
                ->push(['result' => 930], 200)
                ->push(['error' => 'ERROR_CORE', 'error_description' => 'Chunk failed'], 200),
        ]);

        $this->runHistoryExportJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED, $contact->bitrix24_history_sync_status);
        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $first->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '930',
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $second->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
        ]);
        $this->assertDatabaseMissing('bitrix24_message_exports', [
            'message_id' => $third->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
        ]);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'history_export_chunk_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_history_export_rerun_exports_only_remaining_messages_after_partial_failure(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $alreadyExported = $this->makeMessage($contact, $identity, 'Уже экспортировано');
        $failed = $this->makeMessage($contact, $identity, 'Нужно повторить');
        $fresh = $this->makeMessage($contact, $identity, 'Новый кусок');

        Bitrix24MessageExport::query()->create([
            'message_id' => $alreadyExported->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => 'existing-entry',
            'exported_at' => now()->subMinutes(2),
        ]);
        Bitrix24MessageExport::query()->create([
            'message_id' => $failed->id,
            'contact_id' => $contact->id,
            'bitrix24_contact_id' => $contact->bitrix24_contact_id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_FAILED,
            'failed_at' => now()->subMinute(),
            'failure_reason' => 'Chunk failed',
        ]);

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::response([
                'result' => 940,
            ], 200),
        ]);

        $this->runHistoryExportJob($contact);

        $contact->refresh();

        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_SYNCED, $contact->bitrix24_history_sync_status);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $alreadyExported->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'bitrix24_timeline_entry_id' => 'existing-entry',
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $failed->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '940',
        ]);
        $this->assertDatabaseHas('bitrix24_message_exports', [
            'message_id' => $fresh->id,
            'export_mode' => Bitrix24MessageExport::MODE_HISTORY,
            'export_status' => Bitrix24MessageExport::STATUS_EXPORTED,
            'bitrix24_timeline_entry_id' => '940',
        ]);

        Http::assertSent(function ($request) use ($alreadyExported, $failed, $fresh): bool {
            $comment = (string) ($request['fields']['COMMENT'] ?? '');

            return ! str_contains($comment, $alreadyExported->text)
                && str_contains($comment, $failed->text)
                && str_contains($comment, $fresh->text);
        });
    }

    public function test_history_export_splits_comments_by_character_limit(): void
    {
        $this->makeActiveConnection();
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);
        $identity = $contact->primaryIdentity()->firstOrFail();

        $first = $this->makeLongMessage($contact, $identity, 'Большой первый', 5000);
        $second = $this->makeLongMessage($contact, $identity, 'Большой второй', 5000);

        Http::fake([
            'https://client-endpoint.example/rest/crm.timeline.comment.add.json' => Http::sequence()
                ->push(['result' => 950], 200)
                ->push(['result' => 951], 200),
        ]);

        $this->runHistoryExportJob($contact);

        Http::assertSentCount(2);
        $sentComments = Http::recorded()
            ->map(static fn (array $pair): string => (string) ($pair[0]['fields']['COMMENT'] ?? ''))
            ->all();

        $this->assertCount(2, $sentComments);
        $this->assertTrue(str_contains($sentComments[0], $first->text));
        $this->assertFalse(str_contains($sentComments[0], $second->text));
        $this->assertTrue(str_contains($sentComments[1], $second->text));
    }

    public function test_history_job_rethrows_retryable_exception_and_keeps_pending_gate_closed(): void
    {
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);

        $readyAction = Mockery::mock(IsContactReadyForBitrix24HistoryExportAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andReturn(true);

        $historyAction = Mockery::mock(SyncChatHistoryToBitrix24Action::class);
        $historyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andThrow(new \RuntimeException('History export exploded.'));

        $job = new SyncChatHistoryToBitrix24Job($contact->id);

        try {
            $job->handle(
                app(ResolveRootContactAction::class),
                $readyAction,
                $historyAction,
                app(LogBitrix24ApiCallAction::class),
            );

            $this->fail('Expected history export job to bubble the retryable exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('History export exploded.', $exception->getMessage());
        }

        $contact->refresh();

        $this->assertTrue($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING, $contact->bitrix24_history_sync_status);
        $this->assertDatabaseMissing('bitrix24_sync_logs', [
            'operation' => 'history_export_failed',
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
        ]);
    }

    public function test_history_job_marks_contact_as_failed_on_final_attempt(): void
    {
        $contact = $this->createHistoryReadyRootContact([
            'bitrix24_history_sync_pending' => true,
            'bitrix24_history_sync_status' => Contact::BITRIX24_HISTORY_SYNC_STATUS_PENDING,
        ]);

        $readyAction = Mockery::mock(IsContactReadyForBitrix24HistoryExportAction::class);
        $readyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andReturn(true);

        $historyAction = Mockery::mock(SyncChatHistoryToBitrix24Action::class);
        $historyAction->shouldReceive('handle')
            ->once()
            ->withArgs(fn (Contact $rootContact): bool => $rootContact->is($contact))
            ->andThrow(new \RuntimeException('History export exploded.'));

        $job = (new SyncChatHistoryToBitrix24Job($contact->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;

        $job->handle(
            app(ResolveRootContactAction::class),
            $readyAction,
            $historyAction,
            app(LogBitrix24ApiCallAction::class),
        );

        $contact->refresh();

        $this->assertFalse($contact->bitrix24_history_sync_pending);
        $this->assertSame(Contact::BITRIX24_HISTORY_SYNC_STATUS_FAILED, $contact->bitrix24_history_sync_status);
        $job->assertFailedWith(\RuntimeException::class);
        $this->assertDatabaseHas('bitrix24_sync_logs', [
            'operation' => 'history_export_failed',
            'status' => Bitrix24SyncLog::STATUS_FAILED,
            'entity_type' => 'contact',
            'entity_id' => (string) $contact->id,
            'error_message' => 'History export exploded.',
        ]);
    }

    private function runHistoryExportJob(Contact $contact): void
    {
        $job = new SyncChatHistoryToBitrix24Job($contact->id);
        app()->call([$job, 'handle']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createHistoryReadyRootContact(array $overrides = []): Contact
    {
        $contact = Contact::factory()->create(array_merge([
            'first_name' => 'Герман',
            'country' => 'Россия',
            'city' => 'Москва',
            'age_range' => '24_29',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => '777',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ], $overrides));

        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);

        ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$contact->id,
        ]);

        ContactPhoneNumber::factory()->create([
            'contact_id' => $contact->id,
            'phone_raw' => '+7 999 123 45 67',
            'phone_normalized' => '+79991234567',
            'is_primary' => true,
        ]);

        return $contact->fresh();
    }

    private function makeMessage(Contact $contact, ContactIdentity $identity, string $text): Message
    {
        return Message::factory()->create([
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => $text,
            'received_at' => now(),
        ]);
    }

    private function makeLongMessage(Contact $contact, ContactIdentity $identity, string $prefix, int $length): Message
    {
        return Message::factory()->create([
            'contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $identity->channel_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => $prefix.' '.str_repeat('x', $length),
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeActiveConnection(array $overrides = []): Bitrix24Connection
    {
        return $this->makeProfileLinkedActiveBitrix24Connection($overrides);
    }
}
