<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Dialogs\ResolveDialogRouteStatusAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class DialogRoutePredicateParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_ready_scope_matches_resolver_sendable_dialogs(): void
    {
        $dialogs = $this->createRouteMatrix();

        $expectedReadyIds = $dialogs
            ->filter(fn (Dialog $dialog): bool => app(ResolveDialogRouteStatusAction::class)->handle($dialog)->isSendable)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $actualReadyIds = Dialog::query()
            ->whereKey($dialogs->pluck('id')->all())
            ->whereRouteReady()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedReadyIds, $actualReadyIds);
    }

    public function test_route_problem_scope_matches_resolver_non_sendable_dialogs(): void
    {
        $dialogs = $this->createRouteMatrix();

        $expectedProblemIds = $dialogs
            ->reject(fn (Dialog $dialog): bool => app(ResolveDialogRouteStatusAction::class)->handle($dialog)->isSendable)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $actualProblemIds = Dialog::query()
            ->whereKey($dialogs->pluck('id')->all())
            ->whereRouteProblem()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame($expectedProblemIds, $actualProblemIds);
    }

    public function test_route_ready_and_route_problem_partition_same_dialog_set_without_overlap(): void
    {
        $dialogs = $this->createRouteMatrix();
        $allIds = $dialogs->pluck('id')->sort()->values()->all();
        $readyIds = Dialog::query()
            ->whereKey($allIds)
            ->whereRouteReady()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();
        $problemIds = Dialog::query()
            ->whereKey($allIds)
            ->whereRouteProblem()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([], array_values(array_intersect($readyIds, $problemIds)));
        $this->assertEqualsCanonicalizing($allIds, array_merge($readyIds, $problemIds));
    }

    /**
     * @return Collection<int, Dialog>
     */
    protected function createRouteMatrix(): Collection
    {
        return collect([
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-ready-token'],
                ],
                dialogAttributes: [
                    'external_chat_id' => 'telegram-ready-chat',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_MAX,
                    'credentials' => ['token' => 'max-user-route-token'],
                ],
                identityAttributes: [
                    'external_user_id' => 'max-user-route-100',
                ],
                dialogAttributes: [
                    'external_chat_id' => null,
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-blocked-token'],
                ],
                dialogAttributes: [
                    'external_chat_id' => 'telegram-blocked-chat',
                    'bot_subscription_status' => Dialog::BOT_SUBSCRIPTION_STATUS_BLOCKED_BY_USER,
                    'bot_subscription_changed_at' => now(),
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-disconnected-token'],
                    'connection_status' => Channel::CONNECTION_STATUS_NOT_CONNECTED,
                    'webhook_status' => Channel::WEBHOOK_STATUS_NOT_INSTALLED,
                    'connection_checked_at' => now(),
                    'connection_error_message' => 'Webhook установлен не на эту админку',
                ],
                dialogAttributes: [
                    'external_chat_id' => 'telegram-disconnected-chat',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-stale-token'],
                    'connection_status' => Channel::CONNECTION_STATUS_CONNECTED,
                    'webhook_status' => Channel::WEBHOOK_STATUS_INSTALLED,
                    'connection_checked_at' => now()->subMinutes(3),
                ],
                dialogAttributes: [
                    'external_chat_id' => 'telegram-stale-chat',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => [],
                ],
                dialogAttributes: [
                    'external_chat_id' => 'telegram-missing-token-chat',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-missing-chat-token'],
                ],
                dialogAttributes: [
                    'external_chat_id' => '',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_MAX,
                    'credentials' => ['token' => 'max-missing-route-token'],
                ],
                identityAttributes: [
                    'external_user_id' => '',
                ],
                dialogAttributes: [
                    'external_chat_id' => '',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_MAX,
                    'credentials' => ['token' => 'max-inactive-token'],
                    'is_active' => false,
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'credentials' => ['token' => 'telegram-non-bot-token'],
                    'connection_type' => 'webhook',
                ],
            ),
            $this->createDialog(
                channelAttributes: [
                    'platform' => 'whatsapp',
                    'credentials' => ['token' => 'unsupported-token'],
                ],
            ),
        ]);
    }

    protected function createDialog(
        array $channelAttributes = [],
        array $identityAttributes = [],
        array $dialogAttributes = [],
    ): Dialog {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create(array_merge([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'credentials' => ['token' => 'default-token'],
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
            'is_active' => true,
        ], $channelAttributes));
        $identity = ContactIdentity::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => $channel->platform,
            'external_user_id' => 'external-user-100',
        ], $identityAttributes));

        return Dialog::factory()->create(array_merge([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => 'chat-100',
        ], $dialogAttributes));
    }
}
