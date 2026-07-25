<?php

namespace Tests\Feature;

use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Bitrix24OpenLineRouteSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('bitrix24_open_line_routes', [
            'id',
            'bitrix24_profile_id',
            'channel_id',
            'portal_domain',
            'profile_key',
            'channel_type',
            'connector_code',
            'line_id',
            'line_owner_key',
            'callback_owner_id',
            'source_id',
            'status',
            'last_error_message',
            'last_error_at',
            'created_by_user_id',
            'updated_by_user_id',
        ]));

        $this->assertTrue(Schema::hasColumn('dialogs', 'bitrix24_open_line_route_id'));
        $this->assertTrue(Schema::hasColumns('bitrix24_callback_owners', [
            'id',
            'bitrix24_profile_id',
            'owner_key',
            'display_name',
            'callback_base_url',
            'status',
            'last_seen_at',
        ]));
    }

    public function test_usable_routes_claim_line_owner_key_and_drafts_keep_it_null(): void
    {
        $profile = $this->makeProfile();
        $firstChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $secondChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $thirdChannel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $activeRoute = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $firstChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($firstChannel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'line-telegram',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $draftRoute = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $secondChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($secondChannel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'line-telegram',
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);

        $this->assertSame('crm.example.test#line-telegram', $activeRoute->line_owner_key);
        $this->assertNull($draftRoute->line_owner_key);

        $this->expectException(QueryException::class);

        Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $thirdChannel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($thirdChannel),
            'connector_code' => 'abrikosoff_max',
            'line_id' => 'line-telegram',
            'status' => Bitrix24OpenLineRoute::STATUS_LEGACY,
        ]);
    }

    public function test_dialog_can_be_pinned_to_open_line_route(): void
    {
        $profile = $this->makeProfile();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $identity = ContactIdentity::factory()->create([
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => 'line-telegram',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $dialog = Dialog::factory()->create([
            'current_contact_identity_id' => $identity->id,
            'contact_id' => $identity->contact_id,
            'channel_id' => $channel->id,
            'bitrix24_open_line_route_id' => $route->id,
        ]);

        $this->assertTrue($dialog->bitrix24OpenLineRoute->is($route));
        $this->assertTrue($route->dialogs()->whereKey($dialog->id)->exists());
    }

    public function test_channel_type_is_resolved_from_channel_platform_and_connection_type(): void
    {
        $telegramBot = Channel::factory()->make([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $telegramAccount = Channel::factory()->account()->make([
            'platform' => Channel::PLATFORM_TELEGRAM,
        ]);
        $maxBot = Channel::factory()->make([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);

        $this->assertSame(Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT, Bitrix24OpenLineRoute::channelTypeForChannel($telegramBot));
        $this->assertSame(Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT, Bitrix24OpenLineRoute::channelTypeForChannel($telegramAccount));
        $this->assertSame(Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX, Bitrix24OpenLineRoute::channelTypeForChannel($maxBot));
    }

    public function test_openlines_connector_type_policy_supports_telegram_bot_and_max_only(): void
    {
        $this->assertSame(
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM,
            Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
                Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            ),
        );
        $this->assertSame(
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX,
            Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
                Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            ),
        );
        $this->assertNull(
            Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
                Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT,
            ),
        );
        $this->assertNull(Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType('unsupported'));
    }

    private function makeProfile(): Bitrix24Profile
    {
        return Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.example.test',
            'profile_key' => 'staging',
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'callback_base_url' => 'https://project.example.test',
        ]);
    }
}
