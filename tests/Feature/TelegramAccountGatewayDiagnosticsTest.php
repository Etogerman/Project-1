<?php

namespace Tests\Feature;

use App\Data\TelegramAccount\TelegramAccountGatewayDiagnosticsData;
use App\Models\Channel;
use App\Models\ChannelRuntimeState;
use App\Services\TelegramAccount\ResolveTelegramAccountGatewayDiagnosticsAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TelegramAccountGatewayDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_diagnostics_reports_ready_outgoing_replies(): void
    {
        $channel = $this->createAccountChannelWithRuntime([
            'runtime_payload' => [
                'gateway_capabilities' => [
                    'outgoing_replies' => true,
                ],
            ],
        ]);

        $diagnostics = $this->resolve($channel);

        $this->assertSame(TelegramAccountGatewayDiagnosticsData::CODE_READY, $diagnostics->code);
        $this->assertSame('Исходящие ответы готовы', $diagnostics->label);
        $this->assertSame('success', $diagnostics->severity);
        $this->assertTrue($diagnostics->isOutgoingReplyReady);
        $this->assertTrue($channel->fresh('runtimeState')->hasReadyTelegramAccountGatewayOutgoingReplies());
    }

    public function test_gateway_diagnostics_reports_reason_priority_for_blocked_outgoing_replies(): void
    {
        $cases = [
            'runtime_state_missing' => [
                'channel' => fn (): Channel => Channel::factory()->account()->create([
                    'platform' => Channel::PLATFORM_TELEGRAM,
                    'is_active' => true,
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_RUNTIME_STATE_MISSING,
                'label' => 'Gateway ещё не прислал runtime-состояние',
                'severity' => 'gray',
            ],
            'channel_inactive' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime(channelAttributes: [
                    'is_active' => false,
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_CHANNEL_INACTIVE,
                'label' => 'Канал отключён',
                'severity' => 'gray',
            ],
            'auth_not_authorized' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'auth_status' => ChannelRuntimeState::AUTH_STATUS_PENDING,
                    'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_QR,
                    'sync_status' => ChannelRuntimeState::SYNC_STATUS_IDLE,
                    'last_gateway_heartbeat_at' => null,
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_AUTH_NOT_AUTHORIZED,
                'label' => 'Telegram account не авторизован',
                'severity' => 'warning',
            ],
            'authorization_not_ready' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_AWAITING_CODE,
                    'sync_status' => ChannelRuntimeState::SYNC_STATUS_IDLE,
                    'last_gateway_heartbeat_at' => null,
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_AUTHORIZATION_NOT_READY,
                'label' => 'Авторизация Telegram account ещё не готова',
                'severity' => 'warning',
            ],
            'sync_not_live' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'sync_status' => ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS,
                    'last_gateway_heartbeat_at' => null,
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_SYNC_NOT_LIVE,
                'label' => 'Синхронизация Telegram account не в реальном времени',
                'severity' => 'warning',
            ],
            'sync_degraded' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'sync_status' => ChannelRuntimeState::SYNC_STATUS_DEGRADED,
                    'runtime_payload' => [
                        'gateway_capabilities' => [
                            'outgoing_replies' => true,
                        ],
                    ],
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_SYNC_NOT_LIVE,
                'label' => 'Синхронизация Telegram account не в реальном времени',
                'severity' => 'warning',
            ],
            'heartbeat_stale' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'last_gateway_heartbeat_at' => now()->subMinutes(Channel::GATEWAY_HEARTBEAT_FRESH_FOR_MINUTES + 1),
                    'runtime_payload' => [
                        'gateway_capabilities' => [
                            'outgoing_replies' => true,
                        ],
                    ],
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_HEARTBEAT_STALE,
                'label' => 'Heartbeat gateway устарел',
                'severity' => 'danger',
            ],
            'outgoing_replies_unconfirmed' => [
                'channel' => fn (): Channel => $this->createAccountChannelWithRuntime([
                    'runtime_payload' => [
                        'gateway_capabilities' => [
                            'outgoing_replies' => false,
                        ],
                    ],
                ]),
                'code' => TelegramAccountGatewayDiagnosticsData::CODE_OUTGOING_REPLIES_UNCONFIRMED,
                'label' => 'Gateway не подтвердил отправку исходящих ответов',
                'severity' => 'warning',
            ],
        ];

        foreach ($cases as $case) {
            $channel = $case['channel']();
            $diagnostics = $this->resolve($channel);

            $this->assertSame($case['code'], $diagnostics->code);
            $this->assertSame($case['label'], $diagnostics->label);
            $this->assertSame($case['severity'], $diagnostics->severity);
            $this->assertFalse($diagnostics->isOutgoingReplyReady);
            $this->assertFalse($channel->fresh('runtimeState')->hasReadyTelegramAccountGatewayOutgoingReplies());
        }
    }

    /**
     * @param  array<string, mixed>  $runtimeAttributes
     * @param  array<string, mixed>  $channelAttributes
     */
    private function createAccountChannelWithRuntime(array $runtimeAttributes = [], array $channelAttributes = []): Channel
    {
        $channel = Channel::factory()->account()->create(array_merge([
            'platform' => Channel::PLATFORM_TELEGRAM,
            'is_active' => true,
        ], $channelAttributes));

        ChannelRuntimeState::query()->create(array_merge([
            'channel_id' => $channel->id,
            'auth_status' => ChannelRuntimeState::AUTH_STATUS_AUTHORIZED,
            'authorization_state' => ChannelRuntimeState::AUTHORIZATION_STATE_READY,
            'sync_status' => ChannelRuntimeState::SYNC_STATUS_LIVE,
            'last_gateway_heartbeat_at' => now(),
            'runtime_payload' => [],
        ], $runtimeAttributes));

        return $channel->fresh('runtimeState');
    }

    private function resolve(Channel $channel): TelegramAccountGatewayDiagnosticsData
    {
        return app(ResolveTelegramAccountGatewayDiagnosticsAction::class)->handle($channel->fresh('runtimeState'));
    }
}
