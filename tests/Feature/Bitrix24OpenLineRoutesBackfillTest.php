<?php

namespace Tests\Feature;

use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Bitrix24\BackfillBitrix24OpenLineRoutesAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24OpenLineRoutesBackfillTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    public function test_backfill_creates_legacy_route_from_old_profile_fields_and_pins_dialogs(): void
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
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-old-1');

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $connection->profile_id)
            ->where('channel_id', $channel->id)
            ->firstOrFail();

        $this->assertSame(1, $result['routes_created']);
        $this->assertSame(1, $result['dialogs_pinned']);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_LEGACY, $route->status);
        $this->assertSame('abrikosoff_telegram_old', $route->connector_code);
        $this->assertSame('line-old', $route->line_id);
        $this->assertSame('ABRIKOSOFF_TELEGRAM_OLD', $route->source_id);
        $this->assertSame('crm.alexlesley.biz#line-old', $route->line_owner_key);
        $this->assertSame($route->id, $dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_reuses_existing_active_route_and_pins_old_dialogs(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram',
            'telegram_line_id' => 'line-telegram',
            'max_connector_code' => null,
            'max_line_id' => null,
            'max_source_id' => null,
        ]);
        $channel = $this->makeTelegramBotChannel();
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $connection->profile_id,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'line-telegram',
            'source_id' => 'ABRIKOSOFF_TELEGRAM',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $dialog = $this->makeLiveDialog($channel, 'b24-chat-active-1');

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(1, $result['dialogs_pinned']);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $route->fresh()->status);
        $this->assertSame($route->id, $dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_skips_ambiguous_old_line_instead_of_guessing_channel(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => 'line-old',
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
            'line_id' => 'line-old',
        ]);
        $this->assertNull($firstDialog->fresh()->bitrix24_open_line_route_id);
        $this->assertNull($secondDialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_backfill_does_not_turn_draft_route_into_conflicting_legacy_route(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_old',
            'telegram_line_id' => 'line-old',
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
            'line_id' => 'line-old',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $result = app(BackfillBitrix24OpenLineRoutesAction::class)->handle();

        $this->assertSame(0, $result['routes_created']);
        $this->assertSame(0, $result['routes_updated']);
        $this->assertSame(0, $result['dialogs_pinned']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_INACTIVE, $draftRoute->fresh()->status);
        $this->assertSame('crm.alexlesley.biz#line-old', $ownerRoute->fresh()->line_owner_key);
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
