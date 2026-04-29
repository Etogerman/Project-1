<?php

namespace Tests\Feature;

use App\Filament\Resources\Bitrix24Connections\Pages\ListBitrix24Connections;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\User;
use App\Services\Bitrix24\BuildBitrix24AdminOAuthAuthorizeUrlAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Feature\Concerns\InteractsWithBitrix24RuntimeProfile;
use Tests\TestCase;

class Bitrix24AdminOAuthConnectTest extends TestCase
{
    use InteractsWithBitrix24RuntimeProfile;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::bootCurrentPanel();

        config()->set('bitrix24.application.client_secret', 'client-secret');
        config()->set('bitrix24.oauth.server_url', 'https://oauth.bitrix.info');
    }

    public function test_superadmin_can_start_oauth_connection_for_current_runtime_profile(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();

        $response = $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.start'));

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringStartsWith('https://crm.alexlesley.biz/oauth/authorize/?', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertSame($profile->client_id, $query['client_id'] ?? null);
        $this->assertSame($profile->adminOAuthCallbackUrl(), $query['redirect_uri'] ?? null);
        $this->assertNotEmpty($query['state'] ?? null);
        $this->assertTrue(Cache::has(BuildBitrix24AdminOAuthAuthorizeUrlAction::cacheKey((string) $query['state'])));

        $this->actingAs($superadmin)
            ->get(route('filament.admin.resources.bitrix24-connections.index'))
            ->assertOk()
            ->assertSee('Подключить Bitrix24');
    }

    public function test_regular_admin_can_view_diagnostics_but_does_not_see_write_actions(): void
    {
        $admin = User::factory()->create([
            'is_active' => true,
            'is_admin' => true,
            'role' => User::ROLE_ADMIN,
        ]);
        $connection = $this->makeConnectionWithProfile();

        $this->actingAs($admin)
            ->get(route('filament.admin.resources.bitrix24-connections.index'))
            ->assertOk()
            ->assertDontSee('Подключить Bitrix24');

        Livewire::actingAs($admin)
            ->test(ListBitrix24Connections::class)
            ->assertTableActionHidden('checkConnection', $connection)
            ->assertTableActionHidden('disconnectLocally', $connection)
            ->assertTableActionHidden('resetLocally', $connection);
    }

    public function test_header_action_uses_reconnect_label_when_local_record_exists(): void
    {
        $superadmin = $this->makeSuperadmin();

        $this->actingAs($superadmin)
            ->get(route('filament.admin.resources.bitrix24-connections.index'))
            ->assertOk()
            ->assertSee('Подключить Bitrix24');

        $this->makeConnectionWithProfile([
            'status' => Bitrix24Connection::STATUS_NEEDS_REINSTALL,
        ]);

        $this->actingAs($superadmin)
            ->get(route('filament.admin.resources.bitrix24-connections.index'))
            ->assertOk()
            ->assertSee('Переподключить Bitrix24')
            ->assertSee('Сбросить');
    }

    public function test_oauth_callback_creates_connection_and_reconnect_updates_without_duplicate(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();

        Http::fake([
            'https://oauth.bitrix.info/oauth/token/' => Http::sequence()
                ->push($this->tokenPayload('member-1', 'access-token-1', 'refresh-token-1'))
                ->push($this->tokenPayload('member-1', 'access-token-2', 'refresh-token-2')),
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                ],
            ]),
        ]);

        $this->putOAuthState('state-1', $superadmin, $profile);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code-1',
                'state' => 'state-1',
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'scope' => 'crm im',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $connection = Bitrix24Connection::query()->sole();

        $this->assertSame($profile->id, $connection->profile_id);
        $this->assertSame('crm.alexlesley.biz', $connection->portal_domain);
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertSame('access-token-1', $connection->access_token_encrypted);
        $this->assertSame('refresh-token-1', $connection->refresh_token_encrypted);
        $this->assertSame(['crm', 'im'], $connection->scope);

        $connection->forceFill([
            'application_token_hash' => hash('sha256', 'install-callback-token'),
        ])->save();

        $this->putOAuthState('state-2', $superadmin, $profile);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code-2',
                'state' => 'state-2',
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'scope' => 'crm im',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $this->assertSame(1, Bitrix24Connection::query()->count());
        $connection->refresh();
        $this->assertSame('access-token-2', $connection->access_token_encrypted);
        $this->assertSame('refresh-token-2', $connection->refresh_token_encrypted);
        $this->assertSame(hash('sha256', 'install-callback-token'), $connection->application_token_hash);
    }

    public function test_oauth_return_to_install_callback_path_redirects_to_admin_oauth_callback(): void
    {
        $this->makeRuntimeProfile();

        $query = [
            'code' => 'auth-code',
            'state' => 'state-1',
            'domain' => 'crm.alexlesley.biz',
            'member_id' => 'member-1',
            'scope' => 'crm im',
            'server_domain' => 'oauth.bitrix.info',
        ];

        $queryString = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $response = $this->get('https://project.example.com/callbacks/bitrix24/install?'.$queryString);

        $response->assertRedirect('https://project.example.com/admin/bitrix24/oauth/callback?'.$queryString);

        $this->assertSame(0, Bitrix24WebhookEvent::query()->count());
    }

    public function test_oauth_callback_accepts_trusted_oauth_server_client_endpoint_and_stores_portal_rest_endpoint(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();
        $this->putOAuthState('state-1', $superadmin, $profile);

        Http::fake([
            'https://oauth.bitrix.info/oauth/token/' => Http::response(array_merge(
                $this->tokenPayload('member-1'),
                [
                    'domain' => 'oauth.bitrix.info',
                    'client_endpoint' => 'https://oauth.bitrix.info/rest/',
                    'server_endpoint' => 'https://oauth.bitrix.info/rest/',
                ],
            )),
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                ],
            ]),
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'state-1',
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $connection = Bitrix24Connection::query()->sole();

        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertSame('https://crm.alexlesley.biz/rest/', $connection->client_endpoint);
        $this->assertSame('https://oauth.bitrix.info', $connection->server_endpoint);
    }

    public function test_oauth_callback_rejects_invalid_state_without_saving_connection(): void
    {
        $superadmin = $this->makeSuperadmin();
        $this->makeRuntimeProfile();

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'missing-state',
                'domain' => 'crm.alexlesley.biz',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $this->assertSame(0, Bitrix24Connection::query()->count());
    }

    public function test_oauth_callback_rejects_portal_mismatch_without_saving_connection(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();
        $this->putOAuthState('state-1', $superadmin, $profile);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'state-1',
                'domain' => 'wrong.example.com',
                'member_id' => 'member-1',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $this->assertSame(0, Bitrix24Connection::query()->count());
    }

    public function test_oauth_callback_rejects_unexpected_application_code_without_saving_connection(): void
    {
        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();
        $this->putOAuthState('state-1', $superadmin, $profile);

        Http::fake([
            'https://oauth.bitrix.info/oauth/token/' => Http::response($this->tokenPayload('member-1')),
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'other.app.code',
                ],
            ]),
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'state-1',
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $this->assertSame(0, Bitrix24Connection::query()->count());
    }

    public function test_oauth_callback_rejects_explicitly_uninstalled_app_without_saving_connection(): void
    {
        config()->set('bitrix24.install_validation.allow_uninstalled_app_probe', false);

        $superadmin = $this->makeSuperadmin();
        $profile = $this->makeRuntimeProfile();
        $this->putOAuthState('state-1', $superadmin, $profile);

        Http::fake([
            'https://oauth.bitrix.info/oauth/token/' => Http::response($this->tokenPayload('member-1')),
            'https://crm.alexlesley.biz/rest/app.info.json' => Http::response([
                'result' => [
                    'CODE' => 'local.app.code',
                    'INSTALLED' => false,
                ],
            ]),
        ]);

        $this->actingAs($superadmin)
            ->get(route('admin.bitrix24.oauth.callback', [
                'code' => 'auth-code',
                'state' => 'state-1',
                'domain' => 'crm.alexlesley.biz',
                'member_id' => 'member-1',
                'server_domain' => 'oauth.bitrix.info',
            ]))
            ->assertRedirect(route('filament.admin.resources.bitrix24-connections.index'));

        $this->assertSame(0, Bitrix24Connection::query()->count());
    }

    public function test_check_connection_button_calls_profile_method_and_clears_error(): void
    {
        $superadmin = $this->makeSuperadmin();
        $connection = $this->makeConnectionWithProfile([
            'status' => Bitrix24Connection::STATUS_NEEDS_REINSTALL,
            'last_error_at' => now(),
            'last_error_message' => 'Old error.',
        ]);

        Http::fake([
            'https://crm.alexlesley.biz/rest/profile.json' => Http::response([
                'result' => [
                    'ID' => 1,
                ],
            ]),
        ]);

        Livewire::actingAs($superadmin)
            ->test(ListBitrix24Connections::class)
            ->assertTableActionVisible('checkConnection', $connection)
            ->callTableAction('checkConnection', $connection);

        $connection->refresh();
        $this->assertSame(Bitrix24Connection::STATUS_ACTIVE, $connection->status);
        $this->assertNull($connection->last_error_at);
        $this->assertNull($connection->last_error_message);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://crm.alexlesley.biz/rest/profile.json'
            && $request['auth'] === 'access-token');

        $this->assertNull(Bitrix24SyncLog::query()->latest('id')->first()?->request_payload['params']['auth'] ?? null);
    }

    public function test_disconnect_button_clears_tokens_and_marks_connection_for_reinstall(): void
    {
        $superadmin = $this->makeSuperadmin();
        $connection = $this->makeConnectionWithProfile();

        Livewire::actingAs($superadmin)
            ->test(ListBitrix24Connections::class)
            ->assertTableActionVisible('disconnectLocally', $connection)
            ->callTableAction('disconnectLocally', $connection);

        $connection->refresh();
        $this->assertSame(Bitrix24Connection::STATUS_NEEDS_REINSTALL, $connection->status);
        $this->assertNull($connection->access_token_encrypted);
        $this->assertNull($connection->refresh_token_encrypted);
        $this->assertNull($connection->access_token_expires_at);
        $this->assertSame(
            'Подключение отключено локально. Для работы нужно подключить заново.',
            $connection->last_error_message,
        );
    }

    public function test_reset_local_record_button_deletes_connection_without_deleting_profile(): void
    {
        $superadmin = $this->makeSuperadmin();
        $connection = $this->makeConnectionWithProfile([
            'status' => Bitrix24Connection::STATUS_NEEDS_REINSTALL,
            'last_error_at' => now(),
            'last_error_message' => 'Old local error.',
        ]);
        $profileId = $connection->profile_id;
        $event = Bitrix24WebhookEvent::query()->forceCreate([
            'connection_id' => $connection->id,
            'callback_type' => Bitrix24WebhookEvent::TYPE_INSTALL,
            'event_name' => '',
            'member_id' => 'member-1',
            'application_token' => 'application-token',
            'portal_domain' => $connection->portal_domain,
            'payload_hash' => hash('sha256', 'reset-local-record-test'),
            'payload' => ['ok' => true],
            'processing_status' => Bitrix24WebhookEvent::STATUS_IGNORED,
        ]);
        $syncLog = Bitrix24SyncLog::query()->forceCreate([
            'connection_id' => $connection->id,
            'direction' => Bitrix24SyncLog::DIRECTION_SYSTEM,
            'operation' => 'reset-local-record-test',
            'status' => Bitrix24SyncLog::STATUS_SKIPPED,
        ]);

        Livewire::actingAs($superadmin)
            ->test(ListBitrix24Connections::class)
            ->assertTableActionVisible('resetLocally', $connection)
            ->callTableAction('resetLocally', $connection)
            ->assertHasNoTableActionErrors();

        $this->assertModelMissing($connection);
        $this->assertDatabaseHas(Bitrix24Profile::class, ['id' => $profileId]);
        $this->assertDatabaseHas(Bitrix24WebhookEvent::class, [
            'id' => $event->id,
            'connection_id' => null,
        ]);
        $this->assertDatabaseHas(Bitrix24SyncLog::class, [
            'id' => $syncLog->id,
            'connection_id' => null,
        ]);
    }

    private function makeSuperadmin(): User
    {
        return User::factory()->create([
            'is_active' => true,
            'role' => User::ROLE_SUPERADMIN,
        ]);
    }

    private function makeRuntimeProfile(): Bitrix24Profile
    {
        $profile = Bitrix24Profile::query()->create([
            'portal_domain' => 'crm.alexlesley.biz',
            'profile_key' => Bitrix24Profile::PROFILE_KEY_STAGING,
            'profile_type' => Bitrix24Profile::TYPE_FULL_LIVE,
            'display_name' => 'Staging',
            'client_id' => 'local.app',
            'application_code' => 'local.app.code',
            'callback_base_url' => 'https://project.example.com',
            'telegram_source_id' => 'ABRIKOSOFF_TELEGRAM',
            'max_source_id' => 'ABRIKOSOFF_MAX',
            'telegram_connector_code' => 'abrikosoff_telegram',
            'max_connector_code' => 'abrikosoff_max',
            'telegram_line_id' => 'line-telegram',
            'max_line_id' => 'line-max',
        ]);

        $this->configureCurrentBitrix24RuntimeProfile($profile);

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $connectionOverrides
     */
    private function makeConnectionWithProfile(array $connectionOverrides = []): Bitrix24Connection
    {
        $profile = $this->makeRuntimeProfile();

        /** @var array<string, mixed> $attributes */
        $attributes = array_merge([
            'profile_id' => $profile->id,
            'portal_domain' => 'crm.alexlesley.biz',
            'application_name' => 'Abrikosoff Connector',
            'client_id' => 'local.app',
            'member_id' => 'member-1',
            'status' => Bitrix24Connection::STATUS_ACTIVE,
            'access_token_encrypted' => 'access-token',
            'refresh_token_encrypted' => 'refresh-token',
            'access_token_expires_at' => now()->addHour(),
            'scope' => ['crm'],
            'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info',
            'install_payload' => [],
            'installed_at' => now()->subHour(),
            'last_install_callback_at' => now()->subHour(),
        ], $connectionOverrides);

        return Bitrix24Connection::query()->forceCreate($attributes);
    }

    private function putOAuthState(string $state, User $user, Bitrix24Profile $profile): void
    {
        Cache::put(BuildBitrix24AdminOAuthAuthorizeUrlAction::cacheKey($state), [
            'user_id' => $user->id,
            'profile_id' => $profile->id,
        ], 600);
    }

    /**
     * @return array<string, mixed>
     */
    private function tokenPayload(
        string $memberId = 'member-1',
        string $accessToken = 'access-token',
        string $refreshToken = 'refresh-token',
    ): array {
        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => 3600,
            'domain' => 'crm.alexlesley.biz',
            'member_id' => $memberId,
            'scope' => 'crm im',
            'client_endpoint' => 'https://crm.alexlesley.biz/rest/',
            'server_endpoint' => 'https://oauth.bitrix.info/rest/',
        ];
    }
}
