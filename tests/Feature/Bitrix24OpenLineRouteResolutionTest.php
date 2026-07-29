<?php

namespace Tests\Feature;

use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Models\Message;
use App\Services\Bitrix24\Bitrix24ApiException;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteMismatchException;
use App\Services\Bitrix24\ExportMessageToBitrix24OpenLinesAction;
use App\Services\Bitrix24\ResolveBitrix24OpenLinesRouteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24OpenLineRouteResolutionTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bitrix24.features.openlines_enabled', true);
        config()->set('bitrix24.features.fake_happy_path_enabled', true);

        $this->fakeBitrix24OpenLineMutationLeases();
    }

    public function test_resolver_uses_usable_route_for_current_profile_and_channel(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram_custom',
            'line_id' => '113',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $resolved = app(ResolveBitrix24OpenLinesRouteAction::class)->handle($dialog);

        $this->assertSame($route->id, $resolved->routeId);
        $this->assertSame(Channel::PLATFORM_TELEGRAM, $resolved->platform);
        $this->assertSame(Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT, $resolved->channelType);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $resolved->status);
        $this->assertSame('abrikosoff_telegram_custom', $resolved->connectorCode);
        $this->assertSame('113', $resolved->lineId);
    }

    public function test_resolver_requires_explicit_route_instead_of_profile_fallback(): void
    {
        $this->makeProfileLinkedActiveBitrix24Connection(profileOverrides: [
            'telegram_connector_code' => 'abrikosoff_telegram_legacy_profile',
            'telegram_line_id' => 'legacy-profile-line',
        ]);
        $dialog = $this->makeDialog();

        $this->expectException(Bitrix24ApiException::class);
        $this->expectExceptionMessage('route is not configured for channel');

        app(ResolveBitrix24OpenLinesRouteAction::class)->handle($dialog);
    }

    public function test_successful_live_export_pins_route_id_on_dialog(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);
        $message = Message::factory()->create([
            'dialog_id' => $dialog->id,
            'contact_id' => $dialog->contact_id,
            'contact_identity_id' => $dialog->current_contact_identity_id,
            'channel_id' => $dialog->channel_id,
            'external_chat_id' => $dialog->external_chat_id,
            'direction' => Message::DIRECTION_INBOUND,
            'message_kind' => Message::KIND_INBOUND_USER,
            'sent_by_type' => Message::SENT_BY_TYPE_CONTACT,
            'text' => 'Сообщение для Bitrix24',
        ]);

        app(ExportMessageToBitrix24OpenLinesAction::class)->handle($message);

        $dialog->refresh();

        $this->assertSame($route->id, $dialog->bitrix24_open_line_route_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
    }

    public function test_resolver_rejects_non_working_route_status(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'status' => Bitrix24OpenLineRoute::STATUS_INACTIVE,
        ]);

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => $route->id,
        ])->save();

        $this->expectException(Bitrix24ApiException::class);
        $this->expectExceptionMessage('non-working status');

        app(ResolveBitrix24OpenLinesRouteAction::class)->handle($dialog);
    }

    public function test_incoming_callback_uses_pinned_active_route(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => $route->id,
        ])->save();

        $resolved = app(ResolveBitrix24OpenLinesRouteAction::class)
            ->handleIncomingCallback($dialog, 'abrikosoff_telegram', '13');

        $this->assertSame($route->id, $resolved->routeId);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $resolved->status);
    }

    public function test_incoming_callback_binds_matching_legacy_route_for_unpinned_dialog(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_LEGACY,
        ]);

        $resolved = app(ResolveBitrix24OpenLinesRouteAction::class)
            ->handleIncomingCallback($dialog, 'abrikosoff_telegram', '13');

        $this->assertSame($route->id, $resolved->routeId);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_LEGACY, $resolved->status);
        $this->assertSame($route->id, $dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_incoming_callback_binds_matching_active_route_for_unpinned_dialog(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $resolved = app(ResolveBitrix24OpenLinesRouteAction::class)
            ->handleIncomingCallback($dialog, 'abrikosoff_telegram', '13');

        $this->assertSame($route->id, $resolved->routeId);
        $this->assertSame(Bitrix24OpenLineRoute::STATUS_ACTIVE, $resolved->status);
        $this->assertSame($route->id, $dialog->fresh()->bitrix24_open_line_route_id);
    }

    public function test_incoming_callback_rejects_unpinned_dialog_when_connector_or_line_does_not_match(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        $this->expectException(Bitrix24OpenLinesRouteMismatchException::class);
        $this->expectExceptionMessage('requires matching usable route');

        app(ResolveBitrix24OpenLinesRouteAction::class)
            ->handleIncomingCallback($dialog, 'abrikosoff_telegram', 'another-line');
    }

    public function test_incoming_callback_requires_connector_and_line(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection();
        $dialog = $this->makeDialog();
        $route = $this->makeRoute($dialog, [
            'bitrix24_profile_id' => $connection->profile_id,
        ]);

        $dialog->forceFill([
            'bitrix24_open_line_route_id' => $route->id,
        ])->save();

        $this->expectException(Bitrix24OpenLinesRouteMismatchException::class);
        $this->expectExceptionMessage('must include connector and line');

        app(ResolveBitrix24OpenLinesRouteAction::class)
            ->handleIncomingCallback($dialog, '', '13');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeDialog(array $attributes = []): Dialog
    {
        $contact = Contact::factory()->create([
            'name' => 'Live Contact',
            'data_collection_status' => Contact::DATA_COLLECTION_STATUS_COMPLETED,
            'bitrix24_contact_id' => 'B24-CONTACT-100',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
            'bitrix24_sync_pending' => false,
        ]);
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'telegram-user-100',
        ]);

        return Dialog::factory()->create(array_merge([
            'current_contact_identity_id' => $identity->id,
            'contact_id' => $identity->contact_id,
            'channel_id' => $channel->id,
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeRoute(Dialog $dialog, array $attributes = []): Bitrix24OpenLineRoute
    {
        $profileId = (int) ($attributes['bitrix24_profile_id'] ?? 0);

        if ($profileId <= 0) {
            $profileId = (int) $this->makeProfileLinkedActiveBitrix24Connection()->profile_id;
        }

        $channel = $dialog->channel()->firstOrFail();
        $profile = Bitrix24Profile::query()->findOrFail($profileId);

        return Bitrix24OpenLineRoute::query()->create(array_merge([
            'bitrix24_profile_id' => $profileId,
            'channel_id' => $channel->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => 'staging',
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => 'abrikosoff_telegram',
            'line_id' => '13',
            'callback_owner_id' => $this->ensureActiveBitrix24CallbackOwner($profile)->id,
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ], $attributes));
    }
}
