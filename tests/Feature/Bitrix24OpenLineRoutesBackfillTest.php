<?php

namespace Tests\Feature;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Bitrix24\BackfillBitrix24OpenLineRoutesAction;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryClient;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24OpenLineRoutesBackfillTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Bitrix24Profile::query()->delete();
    }

    public function test_backfill_does_not_create_usable_route_without_prepublished_registry_owner(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => '42',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM_OLD',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-old-1');
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldNotReceive('acquireLineLease');
            $mock->shouldNotReceive('releaseLineLease');
        });

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('заранее опубликованный usable-маршрут', $result['warnings'][0]);
        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $channel->id,
        ]);
        $this->assertNull($dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_reuses_existing_active_route_only_inside_shared_registry_lease(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram',
            'telegram_line_id' => '43',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM_NEW',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $profile = $connection->profile()->firstOrFail();
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'staging-owner',
            'display_name' => 'Staging owner',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '43',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM_OLD',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-active-1');
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock) use ($owner, $profile): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->withArgs(fn (
                    Bitrix24Profile $usedProfile,
                    Bitrix24CallbackOwner $usedOwner,
                    string $connectorCode,
                    string $connectorType,
                    string $lineId,
                    int $leaseSeconds,
                ): bool => $usedProfile->is($profile)
                    && $usedOwner->is($owner)
                    && $connectorCode === 'abrikosoff_telegram'
                    && $connectorType === Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM
                    && $lineId === '43'
                    && $leaseSeconds >= 180)
                ->andReturn([
                    'lease_token' => str_repeat('a', 64),
                    'expires_at' => now()->addHour()->toIso8601String(),
                ]);
            $mock->shouldReceive('releaseLineLease')
                ->once()
                ->withArgs(fn (
                    Bitrix24Profile $usedProfile,
                    Bitrix24CallbackOwner $usedOwner,
                    string $lineId,
                    string $leaseToken,
                ): bool => $usedProfile->is($profile)
                    && $usedOwner->is($owner)
                    && $lineId === '43'
                    && $leaseToken === str_repeat('a', 64));
        });

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(1, $result['routes_updated']);
        $this->assertSame(1, $result['dialogs_pinned']);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->fresh()->status);
        $this->assertSame('ABRIKOSOFF_TELEGRAM_NEW', $route->fresh()->source_id);
        $this->assertSame($route->id, $dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_rejects_non_numeric_legacy_line_before_registry_or_database_mutation(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => 'line-old',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM_OLD',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-invalid-line');
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldNotReceive('acquireLineLease');
            $mock->shouldNotReceive('releaseLineLease');
        });

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('LINE_ID должен состоять из 1–64 цифр', $result['warnings'][0]);
        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $channel->id,
        ]);
        $this->assertNull($dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_registry_conflict_keeps_existing_route_and_dialog_unchanged(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram',
            'telegram_line_id' => '44',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $profile = $connection->profile()->firstOrFail();
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'conflicted-owner',
            'display_name' => 'Conflicted owner',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '44',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-conflicted-owner');
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->andThrow(new Bitrix24OpenLinesRouteRegistryException(
                    'route_registry_line_owner_conflict',
                ));
            $mock->shouldNotReceive('releaseLineLease');
        });

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString('route_registry_line_owner_conflict', $result['warnings'][0]);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->fresh()->status);
        $this->assertNull($dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_callback_owner_identity_change_after_lease_acquisition_blocks_backfill(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram',
            'telegram_line_id' => '47',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM_NEW',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $profile = $connection->profile()->firstOrFail();
        $owner = Bitrix24CallbackOwner::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'owner_key' => 'owner-before-lease',
            'display_name' => 'Owner before lease',
            'callback_base_url' => $profile->callback_base_url,
            'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '47',
            'callback_owner_id' => $owner->id,
            'source_id' => 'ABRIKOSOFF_TELEGRAM_OLD',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-owner-changed');
        $this->mock(Bitrix24OpenLinesRouteRegistryClient::class, function ($mock) use ($owner): void {
            $mock->shouldReceive('acquireLineLease')
                ->once()
                ->andReturnUsing(function () use ($owner): array {
                    Bitrix24CallbackOwner::query()
                        ->whereKey($owner->getKey())
                        ->update(['owner_key' => 'owner-after-lease']);

                    return [
                        'lease_token' => str_repeat('b', 64),
                        'expires_at' => now()->addHour()->toIso8601String(),
                    ];
                });
            $mock->shouldReceive('releaseLineLease')
                ->once();
        });

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertStringContainsString(
            'Маршрут или callback-владелец изменились после начала backfill',
            $result['warnings'][0],
        );
        $this->assertSame('ABRIKOSOFF_TELEGRAM_OLD', $route->fresh()->source_id);
        $this->assertNull($dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_skips_ambiguous_old_line_instead_of_guessing_channel(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => '45',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $firstChannel = $this->makeTelegramBotChannel();
        $secondChannel = $this->makeTelegramBotChannel();
        $firstDialog = $this->makeLiveDialog($firstChannel, 'b24-chat-1');
        $secondDialog = $this->makeLiveDialog($secondChannel, 'b24-chat-2');

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertDatabaseMissing('bitrix24_open_line_routes', [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram_old',
            'line_id' => '45',
        ]);
        $this->assertNull($firstDialog->fresh()->bitrix24_open_line_route_id);
        $this->assertNull($secondDialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_does_not_turn_draft_route_into_conflicting_legacy_route(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => '46',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $otherChannel = $this->makeTelegramBotChannel();
        $this->makeLiveDialog($channel, 'b24-chat-conflict');
        $draftRoute = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => null,
            'line_id' => null,
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);
        $ownerRoute = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $otherChannel->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'other_connector',
            'line_id' => '46',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_INACTIVE, $draftRoute->fresh()->status);
        $this->assertSame('crm.alexlesley.biz#46', $ownerRoute->fresh()->line_owner_key);
    }

    public function test_command_runs_backfill(): void
    {
        $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => null,
            'telegram_line_id' => null,
            'max_connector_code' => null,
            'max_line_id' => null,
        ]);

        $this->artisan('bitrix24:backfill-openline-routes')
            ->assertSuccessful();
    }

    private function makeTelegramBotChannel(): Channel
    {
        return Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
    }

    private function makeLiveDialog(Channel $channel, string $bitrix24ChatId): Dialog
    {
        $contact = Contact::factory()->create();
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-'.$bitrix24ChatId,
        ]);

        return Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'bitrix24_live_chat_id' => $bitrix24ChatId,
            'bitrix24_live_status' => Dialog::BITRIX24_LIVE_STATUS_ACTIVE,
            'bitrix24_open_line_route_id' => null,
        ]);
    }
}
