<?php

namespace Tests\Feature;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\ContactIdentity;
use App\Models\Dialog;
use App\Services\Bitrix24\Bitrix24ApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24BindOpenLineDialogCommandTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    public function test_command_verifies_and_saves_legacy_open_line_binding(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            connectionOverrides: [
                'scope' => ['imconnector', 'imopenlines'],
            ],
            profileOverrides: [
                'max_connector_code' => 'abrikosoff_max',
                'max_line_id' => '14',
            ],
        );
        $dialog = $this->makeMaxDialog($connection);
        $userCode = sprintf('imol|abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection, $dialog): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ) use ($connection, $dialog): bool {
                    return $method === 'imopenlines.dialog.get'
                        && $params['USER_CODE'] === sprintf('abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id)
                        && $usedConnection->is($connection)
                        && $transportRetry === false;
                })
                ->ordered()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        'id' => '7',
                        'entity_data_2' => 'LEAD|0|COMPANY|0|CONTACT|9|DEAL|12',
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.dialog.get',
                    attemptedRefresh: false,
                ));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ) use ($connection): bool {
                    return $method === 'imopenlines.crm.chat.get'
                        && $params === [
                            'CRM_ENTITY_TYPE' => 'CONTACT',
                            'CRM_ENTITY' => '9',
                            'ACTIVE_ONLY' => 'Y',
                        ]
                        && $usedConnection->is($connection)
                        && $transportRetry === false;
                })
                ->ordered()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        [
                            'CHAT_ID' => '7',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.crm.chat.get',
                    attemptedRefresh: false,
                ));
        });

        $this->artisan('bitrix24:bind-openline-dialog', [
            'dialog' => $dialog->id,
            '--user-code' => $userCode,
            '--chat-id' => '7',
        ])->assertSuccessful();

        $dialog->refresh();

        $this->assertSame(sprintf('abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id), $dialog->bitrix24_open_line_user_code_override);
        $this->assertSame('7', $dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNotNull($dialog->bitrix24_open_line_binding_verified_at);
        $this->assertSame('abrikosoff-dialog:'.$dialog->id, $dialog->bitrix24_live_chat_id);
        $this->assertSame(Dialog::BITRIX24_LIVE_STATUS_ACTIVE, $dialog->bitrix24_live_status);
    }

    public function test_command_does_not_save_when_bitrix_chat_id_mismatches(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'max_connector_code' => 'abrikosoff_max',
                'max_line_id' => '14',
            ],
        );
        $dialog = $this->makeMaxDialog($connection);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        'id' => '17',
                        'entity_data_2' => 'CONTACT|9',
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.dialog.get',
                    attemptedRefresh: false,
                ));
        });

        $this->artisan('bitrix24:bind-openline-dialog', [
            'dialog' => $dialog->id,
            '--user-code' => sprintf('abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id),
            '--chat-id' => '7',
        ])->assertFailed();

        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
    }

    public function test_command_does_not_save_when_bitrix_chat_is_not_active_for_contact(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'max_connector_code' => 'abrikosoff_max',
                'max_line_id' => '14',
            ],
        );
        $dialog = $this->makeMaxDialog($connection);

        $this->mock(Bitrix24ApiClient::class, function ($mock) use ($connection): void {
            $mock->shouldReceive('call')
                ->once()
                ->withArgs(fn (string $method): bool => $method === 'imopenlines.dialog.get')
                ->ordered()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        'id' => '7',
                        'entity_data_2' => 'CONTACT|9',
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.dialog.get',
                    attemptedRefresh: false,
                ));

            $mock->shouldReceive('call')
                ->once()
                ->withArgs(function (
                    string $method,
                    array $params,
                    Bitrix24Connection $usedConnection,
                    bool $transportRetry,
                ) use ($connection): bool {
                    return $method === 'imopenlines.crm.chat.get'
                        && $params === [
                            'CRM_ENTITY_TYPE' => 'CONTACT',
                            'CRM_ENTITY' => '9',
                            'ACTIVE_ONLY' => 'Y',
                        ]
                        && $usedConnection->is($connection)
                        && $transportRetry === false;
                })
                ->ordered()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        [
                            'CHAT_ID' => '24',
                            'CONNECTOR_ID' => 'abrikosoff_max',
                        ],
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.crm.chat.get',
                    attemptedRefresh: false,
                ));
        });

        $this->artisan('bitrix24:bind-openline-dialog', [
            'dialog' => $dialog->id,
            '--user-code' => sprintf('abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id),
            '--chat-id' => '7',
        ])
            ->expectsOutput('Bitrix24 подтвердил USER_CODE, но chat id [7] не найден среди активных чатов CONTACT [9]. Такой binding не подходит для отправки через imopenlines.crm.message.add.')
            ->assertFailed();

        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
    }

    public function test_command_does_not_save_without_synced_bitrix_contact(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'max_connector_code' => 'abrikosoff_max',
                'max_line_id' => '14',
            ],
        );
        $dialog = $this->makeMaxDialog($connection, [
            'bitrix24_contact_id' => null,
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_PENDING,
        ]);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldReceive('call')
                ->once()
                ->andReturn(new Bitrix24RestResponseData(
                    successful: true,
                    httpStatus: 200,
                    result: [
                        'id' => '7',
                        'entity_data_2' => 'CONTACT|9',
                    ],
                    errorCode: null,
                    errorMessage: null,
                    raw: ['result' => true],
                    requestMethod: 'POST',
                    restMethod: 'imopenlines.dialog.get',
                    attemptedRefresh: false,
                ));
        });

        $this->artisan('bitrix24:bind-openline-dialog', [
            'dialog' => $dialog->id,
            '--user-code' => sprintf('abrikosoff_max|14|abrikosoff-dialog:%d|5', $dialog->id),
            '--chat-id' => '7',
        ])
            ->expectsOutput('Диалог нельзя привязать к старой ОЛ до синхронизации контакта с Bitrix24 CONTACT.')
            ->assertFailed();

        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
    }

    public function test_command_does_not_save_when_connector_chat_does_not_match_dialog_key(): void
    {
        $connection = $this->makeProfileLinkedActiveBitrix24Connection(
            profileOverrides: [
                'max_connector_code' => 'abrikosoff_max',
                'max_line_id' => '14',
            ],
        );
        $dialog = $this->makeMaxDialog($connection);

        $this->mock(Bitrix24ApiClient::class, function ($mock): void {
            $mock->shouldNotReceive('call');
        });

        $this->artisan('bitrix24:bind-openline-dialog', [
            'dialog' => $dialog->id,
            '--user-code' => 'abrikosoff_max|14|foreign-dialog:999|5',
            '--chat-id' => '7',
        ])
            ->expectsOutput(sprintf(
                'USER_CODE содержит connector chat [foreign-dialog:999], а для диалога #%d ожидается [abrikosoff-dialog:%d]. Привязка не сохранена.',
                $dialog->id,
                $dialog->id,
            ))
            ->assertFailed();

        $dialog->refresh();

        $this->assertNull($dialog->bitrix24_open_line_user_code_override);
        $this->assertNull($dialog->bitrix24_open_line_resolved_chat_id_override);
        $this->assertNull($dialog->bitrix24_open_line_binding_verified_at);
    }

    /**
     * @param  array<string, mixed>  $contactAttributes
     */
    private function makeMaxDialog(Bitrix24Connection $connection, array $contactAttributes = []): Dialog
    {
        $profile = $connection->profile()->firstOrFail();
        $channel = Channel::factory()->create([
            'platform' => Channel::PLATFORM_MAX,
            'connection_type' => Channel::CONNECTION_TYPE_BOT,
        ]);
        $contact = Contact::factory()->create(array_merge([
            'bitrix24_contact_id' => '9',
            'bitrix24_sync_status' => Contact::BITRIX24_SYNC_STATUS_SYNCED,
        ], $contactAttributes));
        $identity = ContactIdentity::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'platform' => Channel::PLATFORM_MAX,
            'external_user_id' => '228532008',
        ]);
        $route = Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
            'connector_code' => 'abrikosoff_max',
            'line_id' => '14',
            'source_id' => 'ABRIKOSOFF_MAX',
            'status' => Bitrix24OpenLineRoute::STATUS_ACTIVE,
        ]);

        return Dialog::factory()->create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'current_contact_identity_id' => $identity->id,
            'external_chat_id' => '65238156',
            'bitrix24_open_line_route_id' => $route->id,
        ]);
    }
}
