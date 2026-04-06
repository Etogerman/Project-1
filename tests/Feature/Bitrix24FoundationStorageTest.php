<?php

namespace Tests\Feature;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24FoundationStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_bitrix24_connection_encrypts_tokens_and_casts_json_and_datetimes(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.test',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'plain-access-token',
            'refresh_token_encrypted' => 'plain-refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm', 'tasks'],
            'install_payload' => [
                'event' => 'ONAPPINSTALL',
                'auth' => [
                    'domain' => 'crm.alexlesley.biz',
                ],
            ],
            'installed_at' => now(),
        ]);

        $storedAccessToken = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('access_token_encrypted');

        $storedRefreshToken = DB::table('bitrix24_connections')
            ->where('id', $connection->id)
            ->value('refresh_token_encrypted');

        $this->assertIsString($storedAccessToken);
        $this->assertIsString($storedRefreshToken);
        $this->assertStringNotContainsString('plain-access-token', $storedAccessToken);
        $this->assertStringNotContainsString('plain-refresh-token', $storedRefreshToken);

        $connection->refresh();

        $this->assertSame('plain-access-token', $connection->access_token_encrypted);
        $this->assertSame('plain-refresh-token', $connection->refresh_token_encrypted);
        $this->assertIsArray($connection->scope);
        $this->assertSame(['crm', 'tasks'], $connection->scope);
        $this->assertIsArray($connection->install_payload);
        $this->assertNotNull($connection->access_token_expires_at);
        $this->assertNotNull($connection->installed_at);
    }

    public function test_bitrix24_webhook_event_uses_dedupe_constraint_and_array_casts(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $event = Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'portal_domain' => 'crm.alexlesley.biz',
            'payload_hash' => str_repeat('a', 64),
            'payload' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'headers' => [
                'x-bitrix-test' => '1',
            ],
            'query' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        $this->assertIsArray($event->payload);
        $this->assertIsArray($event->headers);
        $this->assertIsArray($event->query);
        $this->assertTrue($event->connection()->is($connection));

        $this->expectException(QueryException::class);

        Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_EVENTS,
            'event_name' => 'ONCRMCONTACTUPDATE',
            'member_id' => 'member-1',
            'application_token' => 'another-token',
            'portal_domain' => 'crm.alexlesley.biz',
            'payload_hash' => str_repeat('a', 64),
            'payload' => [
                'event' => 'ONCRMCONTACTUPDATE',
            ],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);
    }

    public function test_bitrix24_sync_log_is_append_only_and_relates_to_connection(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        $log = Bitrix24SyncLog::query()->create([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'install_upsert',
            'entity_type' => 'connection',
            'entity_id' => (string) $connection->id,
            'request_payload' => [
                'portal_domain' => 'crm.alexlesley.biz',
            ],
            'response_payload' => [
                'status' => 'ok',
            ],
            'status' => Bitrix24SyncLog::STATUS_SUCCESS,
            'http_status' => 200,
            'fingerprint' => 'install-upsert:1',
        ]);

        $this->assertIsArray($log->request_payload);
        $this->assertIsArray($log->response_payload);
        $this->assertNotNull($log->created_at);
        $this->assertNull($log->updated_at);
        $this->assertTrue($log->connection()->is($connection));
        $this->assertFalse(Schema::hasColumn('bitrix24_sync_logs', 'updated_at'));
    }

    public function test_bitrix24_connection_relations_load_events_and_sync_logs(): void
    {
        $connection = Bitrix24Connection::query()->forceCreate([
            'portal_domain' => 'crm.alexlesley.biz',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
        ]);

        Bitrix24WebhookEvent::query()->create([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_INSTALL,
            'event_name' => 'ONAPPINSTALL',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'payload_hash' => str_repeat('b', 64),
            'payload' => ['event' => 'ONAPPINSTALL'],
            'processing_status' => Bitrix24WebhookEvent::STATUS_PENDING,
        ]);

        Bitrix24SyncLog::query()->create([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'callback_validation',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
        ]);

        $connection->refresh();

        $this->assertCount(1, $connection->webhookEvents);
        $this->assertCount(1, $connection->syncLogs);
    }
}
