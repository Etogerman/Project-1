<?php

namespace Tests\Unit;

use Abrikosoff\BitrixBox\OpenLines\RouteRegistry;
use Abrikosoff\BitrixBox\OpenLines\Runtime;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class BitrixBoxOpenLinesRegistryFirstConnectorDiscoveryTest extends TestCase
{
    private string $storageDir;

    public static function setUpBeforeClass(): void
    {
        $sourceDir = dirname(__DIR__, 2).'/bitrix-box/abrikosoff-openlines/local/php_interface/include/abrikosoff_openlines/src';

        require_once $sourceDir.'/RouteRegistry.php';
        require_once $sourceDir.'/Runtime.php';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setRouteRegistrySnapshots([]);
        $this->storageDir = sys_get_temp_dir().'/abrikosoff-route-registry-test-'.bin2hex(random_bytes(8));
        mkdir($this->storageDir, 0700, true);
    }

    protected function tearDown(): void
    {
        $this->setRuntimeConfig(null);
        $this->setRouteRegistrySnapshots([]);

        foreach (glob($this->storageDir.'/*') ?: [] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->storageDir)) {
            rmdir($this->storageDir);
        }

        parent::tearDown();
    }

    public function test_runtime_discovers_registry_only_telegram_and_max_connectors_line_first(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    'dynamic_telegram' => $this->connector('dynamic_telegram', 'telegram'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                    'dynamic_telegram:15' => $this->route('dynamic_telegram', '15', 'Dynamic Telegram'),
                ],
            ),
        ]);
        $this->setRuntimeConfig($this->runtimeConfig());

        $this->assertSame(
            'dynamic_max',
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.max', '14'),
        );
        $this->assertSame(
            'dynamic_telegram',
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.telegram', '15'),
        );

        $maxDefinition = $this->buildConnectorDefinition('dynamic_max');
        $telegramDefinition = $this->buildConnectorDefinition('dynamic_telegram');

        $this->assertSame('abrikosoff:imconnector.max', $maxDefinition['COMPONENT']);
        $this->assertSame('Abrikosoff MAX', $maxDefinition['NAME']);
        $this->assertSame('abrikosoff:imconnector.telegram', $telegramDefinition['COMPONENT']);
        $this->assertSame('Abrikosoff Telegram', $telegramDefinition['NAME']);
        $this->assertContains('dynamic_max', $this->configuredConnectorCodes());
        $this->assertContains('dynamic_telegram', $this->configuredConnectorCodes());

        $lineInfo = Runtime::onInfoLine('14');

        $this->assertIsArray($lineInfo);
        $this->assertSame('dynamic_max', $lineInfo['connector_id']);
        $this->assertSame('Dynamic MAX', $lineInfo['name']);
        $this->assertSame('https://local-1.example.test/callbacks/bitrix24/openlines', $lineInfo['url']);
        $this->assertTrue($this->connectorOwnsLine('dynamic_max', '14'));
        $this->assertSame(
            'https://local-1.example.test/callbacks/bitrix24/openlines',
            Runtime::laravelOpenlinesCallbackUrlForLine('dynamic_max', '14'),
        );
        $this->assertSame(0, $this->eventCount('route_registry_miss'));
    }

    public function test_runtime_reuses_validated_registry_snapshot_during_request(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
        ]);
        $config = $this->runtimeConfig();
        $this->setRuntimeConfig($config);

        $this->assertArrayHasKey(
            'dynamic_max',
            RouteRegistry::activeConnectorDefinitions($config),
        );

        file_put_contents($this->storageDir.'/route_registry.json', '{"schema_version":');

        $lineInfo = Runtime::onInfoLine('14');

        $this->assertIsArray($lineInfo);
        $this->assertSame('dynamic_max', $lineInfo['connector_id']);
        $this->assertSame('Dynamic MAX', $lineInfo['name']);
        $this->assertSame(0, $this->eventCount('route_registry_invalid'));
    }

    public function test_publish_refreshes_and_invalidates_validated_registry_snapshot(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
        ]);
        $config = $this->runtimeConfig();

        $this->assertArrayHasKey(
            'dynamic_max',
            RouteRegistry::activeConnectorDefinitions($config),
        );

        $this->writeRegistry([
            'local-2' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_telegram' => $this->connector('dynamic_telegram', 'telegram'),
                ],
                routes: [
                    'dynamic_telegram:15' => $this->route('dynamic_telegram', '15', 'Dynamic Telegram'),
                ],
                ownerKey: 'local-2',
            ),
        ]);

        $result = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-1',
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
            'snapshot-invalidation',
        );

        $this->assertSame(200, $result['status']);

        $definitions = RouteRegistry::activeConnectorDefinitions($config);

        $this->assertSame(
            [
                'connector_code' => 'dynamic_telegram',
                'connector_type' => 'telegram',
            ],
            $definitions['dynamic_telegram'] ?? null,
        );
        $this->assertSame(
            [
                'connector_code' => 'dynamic_max',
                'connector_type' => 'max',
            ],
            $definitions['dynamic_max'] ?? null,
        );
    }

    public function test_runtime_discovers_numeric_only_max_connector_code(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    '0' => $this->connector('0', 'max'),
                ],
                routes: [
                    '0:14' => $this->route('0', '14', 'Numeric MAX'),
                ],
            ),
        ]);
        $this->setRuntimeConfig($this->runtimeConfig());

        $this->assertSame(
            '0',
            Runtime::connectorCodeForComponentLine('abrikosoff:imconnector.max', '14'),
        );
        $this->assertContains('0', $this->configuredConnectorCodes());
        $this->assertSame('abrikosoff:imconnector.max', $this->buildConnectorDefinition('0')['COMPONENT']);

        $lineInfo = Runtime::onInfoLine('14');

        $this->assertIsArray($lineInfo);
        $this->assertSame('0', $lineInfo['connector_id']);
        $this->assertSame('Numeric MAX', $lineInfo['name']);
        $this->assertSame(0, $this->eventCount('route_registry_miss'));
    }

    public function test_runtime_logs_one_terminal_miss_without_candidate_probe_noise(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
        ]);
        $this->setRuntimeConfig($this->runtimeConfig());

        $this->assertNull(Runtime::onInfoLine('999'));
        $this->assertSame(1, $this->eventCount('route_registry_miss'));
    }

    public function test_runtime_keeps_legacy_snapshot_on_allowlisted_static_fallback(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(connectors: null, routes: []),
        ]);
        $config = $this->runtimeConfig([
            'legacy_telegram' => [
                'name' => 'Legacy Telegram',
                'component' => 'abrikosoff:imconnector.telegram',
                'lines' => [
                    '9' => [
                        'line_name' => 'Legacy Telegram Line',
                        'owner_callback_base_url' => 'https://legacy.example.test',
                    ],
                ],
            ],
        ]);
        $config['route_registry']['transition_fallback_routes'] = ['legacy_telegram:9'];
        $this->setRuntimeConfig($config);

        $lineInfo = Runtime::onInfoLine('9');

        $this->assertIsArray($lineInfo);
        $this->assertSame('legacy_telegram', $lineInfo['connector_id']);
        $this->assertSame('https://legacy.example.test/callbacks/bitrix24/openlines', $lineInfo['url']);
    }

    public function test_runtime_blocks_registry_route_without_catalog_or_static_definition(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [],
                routes: [
                    'unknown_dynamic:16' => $this->route('unknown_dynamic', '16', 'Unknown Dynamic'),
                ],
            ),
        ]);
        $this->setRuntimeConfig($this->runtimeConfig());

        $this->assertNull(Runtime::onInfoLine('16'));
        $this->assertSame(1, $this->eventCount('route_registry_invalid'));
        $this->assertSame(
            'route_registry_connector_missing',
            $this->events()[0]['payload']['error_code'] ?? null,
        );
    }

    public function test_snapshot_returns_exact_stored_registry_catalog_error(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [],
                routes: [
                    'unknown_dynamic:16' => $this->route('unknown_dynamic', '16', 'Unknown Dynamic'),
                ],
            ),
        ]);

        $result = $this->snapshotRequest('invalid-catalog-snapshot');

        $this->assertSame(500, $result['status']);
        $this->assertSame('route_registry_connector_missing', $result['body']['error_code']);
        $this->assertSame(
            'route_registry_connector_missing',
            $this->events()[0]['payload']['error_code'] ?? null,
        );
    }

    public function test_runtime_blocks_registry_type_collision_and_keeps_static_fallback(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'shared_connector' => $this->connector('shared_connector', 'telegram'),
                ],
                routes: [
                    'shared_connector:14' => $this->route('shared_connector', '14', 'Conflicting Telegram'),
                ],
            ),
        ]);
        $config = $this->runtimeConfig([
            'shared_connector' => [
                'name' => 'Static MAX',
                'component' => 'abrikosoff:imconnector.max',
                'lines' => [
                    '9' => [
                        'line_name' => 'Static MAX fallback',
                        'owner_callback_base_url' => 'https://fallback.example.test',
                    ],
                ],
            ],
        ]);
        $config['route_registry']['transition_fallback_routes'] = ['shared_connector:9'];
        $this->setRuntimeConfig($config);

        $this->assertNull(Runtime::onInfoLine('14'));
        $this->assertSame(
            'route_registry_connector_type_conflict',
            $this->events()[0]['payload']['error_code'] ?? null,
        );

        $fallbackLine = Runtime::onInfoLine('9');

        $this->assertIsArray($fallbackLine);
        $this->assertSame('shared_connector', $fallbackLine['connector_id']);
        $this->assertSame('https://fallback.example.test/callbacks/bitrix24/openlines', $fallbackLine['url']);
        $this->assertSame('abrikosoff:imconnector.max', $this->buildConnectorDefinition('shared_connector')['COMPONENT']);
        $this->assertGreaterThanOrEqual(1, $this->eventCount('route_registry_fallback_used'));
    }

    public function test_runtime_blocks_duplicate_active_line_even_when_static_fallback_is_allowlisted(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
            'local-2' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_telegram' => $this->connector('dynamic_telegram', 'telegram'),
                ],
                routes: [
                    'dynamic_telegram:14' => $this->route('dynamic_telegram', '14', 'Dynamic Telegram'),
                ],
                ownerKey: 'local-2',
            ),
        ]);
        $config = $this->runtimeConfig([
            'fallback_max' => [
                'name' => 'Fallback MAX',
                'component' => 'abrikosoff:imconnector.max',
                'line_id' => '14',
            ],
        ]);
        $config['route_registry']['transition_fallback_routes'] = ['fallback_max:14'];
        $this->setRuntimeConfig($config);

        $this->assertNull(Runtime::onInfoLine('14'));
        $this->assertSame(0, $this->eventCount('route_registry_fallback_used'));
        $this->assertSame(
            'route_registry_duplicate_line_id',
            $this->events()[0]['payload']['error_code'] ?? null,
        );
    }

    public function test_publish_rejects_missing_connector_entry_before_registry_write(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [],
            routes: [
                'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
            ],
        );
        $result = $this->publishRequest($payload, 'missing-connector');

        $this->assertSame(422, $result['status']);
        $this->assertSame('route_registry_connector_missing', $result['body']['error_code']);
        $this->assertFileDoesNotExist($this->storageDir.'/route_registry.json');
        $this->assertSame('route_registry_invalid', $this->events()[0]['event'] ?? null);
        $this->assertSame(
            'route_registry_connector_missing',
            $this->events()[0]['payload']['error_code'] ?? null,
        );
    }

    public function test_publish_rejects_connector_type_conflict_without_mutating_registry(): void
    {
        $first = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                'shared_connector' => $this->connector('shared_connector', 'telegram'),
            ],
            routes: [
                'shared_connector:14' => $this->route('shared_connector', '14', 'Telegram'),
            ],
        );
        $second = $this->publishPayload(
            ownerKey: 'local-2',
            connectors: [
                'shared_connector' => $this->connector('shared_connector', 'max'),
            ],
            routes: [
                'shared_connector:15' => $this->route('shared_connector', '15', 'MAX'),
            ],
        );

        $this->assertSame(200, $this->publishRequest($first, 'first-owner')['status']);
        $registryBeforeConflict = file_get_contents($this->storageDir.'/route_registry.json');
        $conflict = $this->publishRequest($second, 'second-owner');

        $this->assertSame(409, $conflict['status']);
        $this->assertSame('route_registry_connector_type_conflict', $conflict['body']['error_code']);
        $this->assertSame($registryBeforeConflict, file_get_contents($this->storageDir.'/route_registry.json'));

        $events = $this->events();
        $lastEvent = $events[array_key_last($events)] ?? [];

        $this->assertSame('route_registry_conflict', $lastEvent['event'] ?? null);
        $this->assertSame('route_registry_connector_type_conflict', $lastEvent['payload']['error_code'] ?? null);
    }

    public function test_publish_rejects_static_connector_type_conflict_without_mutating_registry(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                'shared_connector' => $this->connector('shared_connector', 'max'),
            ],
            routes: [
                'shared_connector:14' => $this->route('shared_connector', '14', 'Registry MAX'),
            ],
        );
        $runtimeConfig = $this->runtimeConfig([
            'shared_connector' => [
                'name' => 'Static Telegram',
                'component' => 'abrikosoff:imconnector.telegram',
                'line_id' => '9',
            ],
        ]);
        $result = $this->publishRequest($payload, 'static-type-conflict', $runtimeConfig);

        $this->assertSame(409, $result['status']);
        $this->assertSame('route_registry_connector_type_conflict', $result['body']['error_code']);
        $this->assertFileDoesNotExist($this->storageDir.'/route_registry.json');

        $events = $this->events();
        $lastEvent = $events[array_key_last($events)] ?? [];

        $this->assertSame('route_registry_conflict', $lastEvent['event'] ?? null);
        $this->assertSame('route_registry_connector_type_conflict', $lastEvent['payload']['error_code'] ?? null);
    }

    public function test_publish_preserves_numeric_only_connector_code_as_json_object_key(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                '0' => $this->connector('0', 'max'),
            ],
            routes: [
                '0:14' => $this->route('0', '14', 'Numeric MAX'),
            ],
        );
        $result = $this->publishRequest($payload, 'numeric-connector');

        $this->assertSame(200, $result['status']);

        $registry = json_decode(
            (string) file_get_contents($this->storageDir.'/route_registry.json'),
        );

        $this->assertIsObject($registry);
        $this->assertIsObject($registry->owners);
        $this->assertIsObject($registry->owners->{'local-1'}->connectors);
        $this->assertSame('0', $registry->owners->{'local-1'}->connectors->{'0'}->connector_code);
        $this->assertSame('max', $registry->owners->{'local-1'}->connectors->{'0'}->connector_type);
    }

    public function test_publish_rejects_unknown_connector_type(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                'unsupported_connector' => $this->connector('unsupported_connector', 'unsupported'),
            ],
            routes: [],
        );
        $result = $this->publishRequest($payload, 'unsupported-type');

        $this->assertSame(422, $result['status']);
        $this->assertSame('route_registry_connector_type_invalid', $result['body']['error_code']);
        $this->assertFileDoesNotExist($this->storageDir.'/route_registry.json');
    }

    public function test_publish_accepts_legacy_owner_snapshot_without_connector_catalog(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: null,
            routes: [
                'legacy_telegram:9' => $this->route('legacy_telegram', '9', 'Legacy Telegram'),
            ],
        );
        $result = $this->publishRequest($payload, 'legacy-owner');

        $this->assertSame(200, $result['status']);

        $registry = json_decode((string) file_get_contents($this->storageDir.'/route_registry.json'), true);

        $this->assertIsArray($registry);
        $this->assertArrayNotHasKey('connectors', $registry['owners']['local-1']);
    }

    public function test_signed_line_lease_serializes_independent_contours_and_rejects_wrong_owner(): void
    {
        $publish = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-1',
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Repairing MAX', false),
                ],
            ),
            'lease-owner-publish',
        );

        $this->assertSame(200, $publish['status']);

        $firstLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
            requestId: 'lease-first-contour',
        );

        $this->assertSame(200, $firstLease['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstLease['body']['lease_token']);

        $sameOwnerSecondContour = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
            requestId: 'lease-second-contour',
        );

        $this->assertSame(409, $sameOwnerSecondContour['status']);
        $this->assertSame('route_registry_line_busy', $sameOwnerSecondContour['body']['error_code']);

        $wrongOwner = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '14'),
            requestId: 'lease-wrong-owner',
        );

        $this->assertSame(409, $wrongOwner['status']);
        $this->assertSame('route_registry_line_owner_conflict', $wrongOwner['body']['error_code']);

        $release = $this->lineLeaseRequest(
            action: 'release-line-lease',
            payload: [
                'portal_domain' => 'stagecrm.fvds.ru',
                'owner_profile_key' => 'local-1',
                'line_id' => '14',
                'lease_token' => $firstLease['body']['lease_token'],
                'lease_scope' => 'connector_registration',
            ],
            requestId: 'lease-release-first-contour',
        );

        $this->assertSame(200, $release['status']);
        $this->assertTrue($release['body']['released']);
        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'acquire-line-lease',
                payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
                requestId: 'lease-after-release',
            )['status'],
        );
    }

    public function test_signed_line_lease_serializes_shared_connector_across_different_lines(): void
    {
        $firstPublish = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-1',
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Primary MAX'),
                ],
            ),
            'shared-connector-first-owner-publish',
        );
        $secondPublish = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-2',
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:15' => $this->route('dynamic_max', '15', 'Secondary MAX'),
                ],
            ),
            'shared-connector-second-owner-publish',
        );

        $this->assertSame(200, $firstPublish['status']);
        $this->assertSame(200, $secondPublish['status']);

        $firstLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
            requestId: 'shared-connector-first-line',
        );

        $this->assertSame(200, $firstLease['status']);

        $secondLine = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15'),
            requestId: 'shared-connector-second-line',
        );

        $this->assertSame(409, $secondLine['status']);
        $this->assertSame('route_registry_connector_busy', $secondLine['body']['error_code']);

        $release = $this->lineLeaseRequest(
            action: 'release-line-lease',
            payload: [
                'portal_domain' => 'stagecrm.fvds.ru',
                'owner_profile_key' => 'local-1',
                'line_id' => '14',
                'lease_token' => $firstLease['body']['lease_token'],
                'lease_scope' => 'connector_registration',
            ],
            requestId: 'shared-connector-first-line-release',
        );

        $this->assertSame(200, $release['status']);
        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'acquire-line-lease',
                payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15'),
                requestId: 'shared-connector-second-line-after-release',
            )['status'],
        );
    }

    public function test_line_runtime_leases_allow_shared_connector_across_different_lines(): void
    {
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-1',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        'dynamic_max:14' => $this->route('dynamic_max', '14', 'Primary MAX'),
                    ],
                ),
                'runtime-shared-connector-first-owner-publish',
            )['status'],
        );
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-2',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        'dynamic_max:15' => $this->route('dynamic_max', '15', 'Secondary MAX'),
                    ],
                ),
                'runtime-shared-connector-second-owner-publish',
            )['status'],
        );

        $firstLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14', 'line_runtime'),
            requestId: 'runtime-shared-connector-first-line',
        );
        $secondLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15', 'line_runtime'),
            requestId: 'runtime-shared-connector-second-line',
        );

        $this->assertSame(200, $firstLease['status']);
        $this->assertSame(200, $secondLease['status']);
        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'release-line-lease',
                payload: [
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'owner_profile_key' => 'local-1',
                    'line_id' => '14',
                    'lease_token' => $firstLease['body']['lease_token'],
                    'lease_scope' => 'line_runtime',
                ],
                requestId: 'runtime-shared-connector-first-line-release',
            )['status'],
        );
        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'release-line-lease',
                payload: [
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'owner_profile_key' => 'local-2',
                    'line_id' => '15',
                    'lease_token' => $secondLease['body']['lease_token'],
                    'lease_scope' => 'line_runtime',
                ],
                requestId: 'runtime-shared-connector-second-line-release',
            )['status'],
        );
    }

    public function test_connector_registration_scope_is_exclusive_against_runtime_on_same_connector(): void
    {
        $this->publishSharedConnectorRoutes();

        $runtimeLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14', 'line_runtime'),
            requestId: 'scope-matrix-runtime-first',
        );
        $registrationWhileRuntime = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15'),
            requestId: 'scope-matrix-registration-blocked',
        );

        $this->assertSame(200, $runtimeLease['status']);
        $this->assertSame(409, $registrationWhileRuntime['status']);
        $this->assertSame('route_registry_connector_busy', $registrationWhileRuntime['body']['error_code']);

        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'release-line-lease',
                payload: [
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'owner_profile_key' => 'local-1',
                    'line_id' => '14',
                    'lease_token' => $runtimeLease['body']['lease_token'],
                    'lease_scope' => 'line_runtime',
                ],
                requestId: 'scope-matrix-runtime-release',
            )['status'],
        );

        $registrationLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15'),
            requestId: 'scope-matrix-registration-first',
        );
        $runtimeWhileRegistration = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14', 'line_runtime'),
            requestId: 'scope-matrix-runtime-blocked',
        );

        $this->assertSame(200, $registrationLease['status']);
        $this->assertSame(409, $runtimeWhileRegistration['status']);
        $this->assertSame('route_registry_connector_busy', $runtimeWhileRegistration['body']['error_code']);
    }

    public function test_legacy_lease_request_without_scope_remains_connector_exclusive(): void
    {
        $this->publishSharedConnectorRoutes();
        $legacyPayload = $this->lineLeasePayload('local-1', 'dynamic_max', '14');
        unset($legacyPayload['lease_scope']);

        $legacyLease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $legacyPayload,
            requestId: 'legacy-scope-acquire',
        );
        $runtimeWhileLegacy = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-2', 'dynamic_max', '15', 'line_runtime'),
            requestId: 'legacy-scope-runtime-blocked',
        );

        $this->assertSame(200, $legacyLease['status']);
        $this->assertSame(409, $runtimeWhileLegacy['status']);
        $this->assertSame('route_registry_connector_busy', $runtimeWhileLegacy['body']['error_code']);

        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'release-line-lease',
                payload: [
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'owner_profile_key' => 'local-1',
                    'line_id' => '14',
                    'lease_token' => $legacyLease['body']['lease_token'],
                ],
                requestId: 'legacy-scope-release',
            )['status'],
        );
    }

    public function test_signed_line_lease_rejects_non_numeric_line_id(): void
    {
        $result = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', 'line-editable'),
            requestId: 'lease-invalid-line-id',
        );

        $this->assertSame(422, $result['status']);
        $this->assertSame('route_registry_route_invalid', $result['body']['error_code']);
    }

    public function test_signed_line_lease_rejects_non_canonical_line_alias(): void
    {
        foreach (['014', ' 14 '] as $index => $lineId) {
            $result = $this->lineLeaseRequest(
                action: 'acquire-line-lease',
                payload: $this->lineLeasePayload('local-1', 'dynamic_max', $lineId),
                requestId: 'lease-non-canonical-line-id-'.$index,
            );

            $this->assertSame(422, $result['status']);
            $this->assertSame('route_registry_route_invalid', $result['body']['error_code']);
            $this->assertFileDoesNotExist($this->storageDir.'/route_registry_line_leases.json');
        }
    }

    public function test_publish_rejects_non_canonical_line_alias(): void
    {
        $cases = [
            ['dynamic_max:014', '014', 'route_registry_route_key_invalid'],
            ['dynamic_max:14', ' 14 ', 'route_registry_route_invalid'],
        ];

        foreach ($cases as $index => [$routeKey, $lineId, $errorCode]) {
            $result = $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-1',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        $routeKey => $this->route('dynamic_max', $lineId, 'Aliased MAX'),
                    ],
                ),
                'publish-non-canonical-line-id-'.$index,
            );

            $this->assertSame(422, $result['status']);
            $this->assertSame($errorCode, $result['body']['error_code']);
            $this->assertFileDoesNotExist($this->storageDir.'/route_registry.json');
        }
    }

    public function test_snapshot_rejects_stored_route_with_non_canonical_line_alias(): void
    {
        $this->writeRegistry([
            'local-1' => $this->ownerSnapshot(
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'max'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', ' 14 ', 'Aliased MAX'),
                ],
            ),
        ]);

        $result = $this->snapshotRequest('snapshot-non-canonical-line-id');

        $this->assertSame(500, $result['status']);
        $this->assertSame('route_registry_invalid', $result['body']['error_code']);
    }

    public function test_line_lease_read_rejects_stored_non_canonical_line_alias_without_rewrite(): void
    {
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-1',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        'dynamic_max:14' => $this->route('dynamic_max', '14', 'Primary MAX'),
                    ],
                ),
                'publish-before-invalid-stored-lease',
            )['status'],
        );

        $leaseFile = $this->storageDir.'/route_registry_line_leases.json';
        $invalidLeases = json_encode([
            '14' => [
                'line_id' => ' 14 ',
                'owner_profile_key' => 'local-1',
                'owner_callback_base_url' => 'https://local-1.example.test',
                'connector_code' => 'dynamic_max',
                'connector_type' => 'max',
                'lease_scope' => 'connector_registration',
                'token_hash' => str_repeat('a', 64),
                'expires_at' => time() + 360,
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertIsString($invalidLeases);
        file_put_contents($leaseFile, $invalidLeases);

        $result = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
            requestId: 'read-invalid-stored-lease',
        );

        $this->assertSame(500, $result['status']);
        $this->assertSame('route_registry_line_leases_invalid', $result['body']['error_code']);
        $this->assertSame($invalidLeases, file_get_contents($leaseFile));
    }

    public function test_publish_cannot_change_line_ownership_while_shared_lease_is_active(): void
    {
        $payload = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                'dynamic_max' => $this->connector('dynamic_max', 'max'),
            ],
            routes: [
                'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
            ],
        );

        $this->assertSame(200, $this->publishRequest($payload, 'leased-publish-owner')['status']);

        $lease = $this->lineLeaseRequest(
            action: 'acquire-line-lease',
            payload: $this->lineLeasePayload('local-1', 'dynamic_max', '14'),
            requestId: 'leased-publish-acquire',
        );
        $registryBeforeConflict = file_get_contents($this->storageDir.'/route_registry.json');
        $changeTypeWhileLeased = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-1',
                connectors: [
                    'dynamic_max' => $this->connector('dynamic_max', 'telegram'),
                ],
                routes: [
                    'dynamic_max:14' => $this->route('dynamic_max', '14', 'Dynamic MAX'),
                ],
            ),
            'leased-publish-type-change',
        );
        $removeWhileLeased = $this->publishRequest(
            $this->publishPayload(
                ownerKey: 'local-1',
                connectors: [],
                routes: [],
            ),
            'leased-publish-remove',
        );

        $this->assertSame(409, $changeTypeWhileLeased['status']);
        $this->assertSame('route_registry_line_busy', $changeTypeWhileLeased['body']['error_code']);
        $this->assertSame(409, $removeWhileLeased['status']);
        $this->assertSame('route_registry_line_busy', $removeWhileLeased['body']['error_code']);
        $this->assertSame($registryBeforeConflict, file_get_contents($this->storageDir.'/route_registry.json'));

        $this->assertSame(
            200,
            $this->lineLeaseRequest(
                action: 'release-line-lease',
                payload: [
                    'portal_domain' => 'stagecrm.fvds.ru',
                    'owner_profile_key' => 'local-1',
                    'line_id' => '14',
                    'lease_token' => $lease['body']['lease_token'],
                    'lease_scope' => 'connector_registration',
                ],
                requestId: 'leased-publish-release',
            )['status'],
        );
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(ownerKey: 'local-1', connectors: [], routes: []),
                'leased-publish-remove-after-release',
            )['status'],
        );
    }

    public function test_inactive_registry_claim_rejects_same_line_from_another_owner(): void
    {
        $first = $this->publishPayload(
            ownerKey: 'local-1',
            connectors: [
                'dynamic_max' => $this->connector('dynamic_max', 'max'),
            ],
            routes: [
                'dynamic_max:14' => $this->route('dynamic_max', '14', 'Repairing MAX', false),
            ],
        );
        $second = $this->publishPayload(
            ownerKey: 'local-2',
            connectors: [
                'dynamic_telegram' => $this->connector('dynamic_telegram', 'telegram'),
            ],
            routes: [
                'dynamic_telegram:14' => $this->route('dynamic_telegram', '14', 'Repairing Telegram', false),
            ],
        );

        $this->assertSame(200, $this->publishRequest($first, 'inactive-claim-first')['status']);

        $conflict = $this->publishRequest($second, 'inactive-claim-second');

        $this->assertSame(409, $conflict['status']);
        $this->assertSame('route_registry_conflict', $conflict['body']['error_code']);
    }

    /**
     * @param  array<string, array<string, mixed>>  $connectors
     * @return array<string, mixed>
     */
    private function runtimeConfig(array $connectors = []): array
    {
        return [
            'laravel' => [
                'openlines_callback_url' => 'https://staging.example.test/callbacks/bitrix24/openlines',
            ],
            'auth' => [
                'portal_domain' => 'stagecrm.fvds.ru',
                'member_id' => 'member-id',
                'application_token' => 'application-token',
            ],
            'connectors' => $connectors + [
                'static_telegram' => [
                    'name' => 'Static Telegram',
                    'component' => 'abrikosoff:imconnector.telegram',
                    'line_id' => '2',
                ],
                'static_max' => [
                    'name' => 'Static MAX',
                    'component' => 'abrikosoff:imconnector.max',
                    'line_id' => '3',
                ],
            ],
            'route_registry' => [
                'enabled' => true,
                'storage_dir' => $this->storageDir,
                'transition_fallback_routes' => [],
            ],
            'crm_rebinding' => [
                'enabled' => false,
                'log_payload' => false,
                'log_file' => $this->storageDir.'/runtime.log',
            ],
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $connectors
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    private function ownerSnapshot(?array $connectors, array $routes, string $ownerKey = 'local-1'): array
    {
        $owner = [
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => 'https://'.$ownerKey.'.example.test',
            'routes' => $routes,
        ];

        if ($connectors !== null) {
            $owner['connectors'] = $connectors;
        }

        return $owner;
    }

    /**
     * @return array{connector_code: string, connector_type: string}
     */
    private function connector(string $connectorCode, string $connectorType): array
    {
        return [
            'connector_code' => $connectorCode,
            'connector_type' => $connectorType,
        ];
    }

    /**
     * @return array{connector_code: string, line_id: string, line_name: string, active: bool}
     */
    private function route(string $connectorCode, string $lineId, string $lineName, bool $active = true): array
    {
        return [
            'connector_code' => $connectorCode,
            'line_id' => $lineId,
            'line_name' => $lineName,
            'active' => $active,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $owners
     */
    private function writeRegistry(array $owners): void
    {
        file_put_contents($this->storageDir.'/route_registry.json', json_encode([
            'schema_version' => 1,
            'portal_domain' => 'stagecrm.fvds.ru',
            'updated_at' => date('c'),
            'owners' => $owners,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, array<string, mixed>>|null  $connectors
     * @param  array<string, array<string, mixed>>  $routes
     * @return array<string, mixed>
     */
    private function publishPayload(string $ownerKey, ?array $connectors, array $routes): array
    {
        $payload = [
            'schema_version' => 1,
            'portal_domain' => 'stagecrm.fvds.ru',
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => 'https://'.$ownerKey.'.example.test',
            'routes' => $routes,
        ];

        if ($connectors !== null) {
            $payload['connectors'] = $connectors;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function publishRequest(array $payload, string $requestId, array $runtimeConfig = []): array
    {
        $path = '/local/tools/abrikosoff_openlines/route-registry.php';
        $timestamp = (string) time();
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($rawBody);

        return RouteRegistry::handleRequest(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => $path,
                'SCRIPT_NAME' => $path,
                'QUERY_STRING' => '',
            ],
            [
                'X-ABR-Timestamp' => $timestamp,
                'X-ABR-Request-Id' => $requestId,
                'X-ABR-Signature' => RouteRegistry::requestSignature(
                    'registry-secret',
                    'POST',
                    $path,
                    '',
                    $timestamp,
                    $requestId,
                    $rawBody,
                ),
            ],
            $rawBody,
            [
                'shared_secret' => 'registry-secret',
                'expected_portal_domain' => 'stagecrm.fvds.ru',
                'allowed_owner_profile_keys' => ['local-1', 'local-2'],
                'storage_dir' => $this->storageDir,
                'validate_dns' => false,
                'runtime_config' => $runtimeConfig,
            ],
        );
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function snapshotRequest(string $requestId): array
    {
        $path = '/local/tools/abrikosoff_openlines/route-registry.php';
        $query = 'action=snapshot';
        $timestamp = (string) time();

        return RouteRegistry::handleRequest(
            [
                'REQUEST_METHOD' => 'GET',
                'REQUEST_URI' => $path.'?'.$query,
                'SCRIPT_NAME' => $path,
                'QUERY_STRING' => $query,
            ],
            [
                'X-ABR-Timestamp' => $timestamp,
                'X-ABR-Request-Id' => $requestId,
                'X-ABR-Signature' => RouteRegistry::requestSignature(
                    'registry-secret',
                    'GET',
                    $path,
                    $query,
                    $timestamp,
                    $requestId,
                    '',
                ),
            ],
            '',
            [
                'shared_secret' => 'registry-secret',
                'expected_portal_domain' => 'stagecrm.fvds.ru',
                'allowed_owner_profile_keys' => ['local-1', 'local-2'],
                'storage_dir' => $this->storageDir,
                'validate_dns' => false,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lineLeasePayload(
        string $ownerKey,
        string $connectorCode,
        string $lineId,
        string $scope = 'connector_registration',
    ): array {
        return [
            'portal_domain' => 'stagecrm.fvds.ru',
            'owner_profile_key' => $ownerKey,
            'owner_callback_base_url' => 'https://'.$ownerKey.'.example.test',
            'connector_code' => $connectorCode,
            'connector_type' => str_contains($connectorCode, 'max') ? 'max' : 'telegram',
            'line_id' => $lineId,
            'lease_seconds' => 360,
            'lease_scope' => $scope,
        ];
    }

    private function publishSharedConnectorRoutes(): void
    {
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-1',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        'dynamic_max:14' => $this->route('dynamic_max', '14', 'Primary MAX'),
                    ],
                ),
                'scope-matrix-first-owner-publish',
            )['status'],
        );
        $this->assertSame(
            200,
            $this->publishRequest(
                $this->publishPayload(
                    ownerKey: 'local-2',
                    connectors: [
                        'dynamic_max' => $this->connector('dynamic_max', 'max'),
                    ],
                    routes: [
                        'dynamic_max:15' => $this->route('dynamic_max', '15', 'Secondary MAX'),
                    ],
                ),
                'scope-matrix-second-owner-publish',
            )['status'],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: array<string, mixed>}
     */
    private function lineLeaseRequest(string $action, array $payload, string $requestId): array
    {
        $path = '/local/tools/abrikosoff_openlines/route-registry.php';
        $query = 'action='.$action;
        $timestamp = (string) time();
        $rawBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($rawBody);

        return RouteRegistry::handleRequest(
            [
                'REQUEST_METHOD' => 'POST',
                'REQUEST_URI' => $path.'?'.$query,
                'SCRIPT_NAME' => $path,
                'QUERY_STRING' => $query,
            ],
            [
                'X-ABR-Timestamp' => $timestamp,
                'X-ABR-Request-Id' => $requestId,
                'X-ABR-Signature' => RouteRegistry::requestSignature(
                    'registry-secret',
                    'POST',
                    $path,
                    $query,
                    $timestamp,
                    $requestId,
                    $rawBody,
                ),
            ],
            $rawBody,
            [
                'shared_secret' => 'registry-secret',
                'expected_portal_domain' => 'stagecrm.fvds.ru',
                'allowed_owner_profile_keys' => ['local-1', 'local-2'],
                'storage_dir' => $this->storageDir,
                'validate_dns' => false,
            ],
        );
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private function setRuntimeConfig(?array $config): void
    {
        $property = new ReflectionProperty(Runtime::class, 'config');
        $property->setValue(null, $config);
    }

    /**
     * @param  array<string, mixed>  $snapshots
     */
    private function setRouteRegistrySnapshots(array $snapshots): void
    {
        $property = new ReflectionProperty(RouteRegistry::class, 'validatedRegistrySnapshots');
        $property->setValue(null, $snapshots);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildConnectorDefinition(string $connectorCode): array
    {
        $method = new ReflectionMethod(Runtime::class, 'buildConnectorDefinition');

        return $method->invoke(null, $connectorCode);
    }

    /**
     * @return list<string>
     */
    private function configuredConnectorCodes(): array
    {
        $method = new ReflectionMethod(Runtime::class, 'configuredConnectorCodes');

        return $method->invoke(null);
    }

    private function connectorOwnsLine(string $connectorCode, string $lineId): bool
    {
        $method = new ReflectionMethod(Runtime::class, 'connectorOwnsLine');

        return (bool) $method->invoke(null, $connectorCode, $lineId);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function events(): array
    {
        $eventFile = $this->storageDir.'/route_registry_events.log';

        if (! is_file($eventFile)) {
            return [];
        }

        $events = [];

        foreach (file($eventFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $event = json_decode($line, true);

            if (is_array($event)) {
                $events[] = $event;
            }
        }

        return $events;
    }

    private function eventCount(string $eventName): int
    {
        return count(array_filter(
            $this->events(),
            static fn (array $event): bool => ($event['event'] ?? null) === $eventName,
        ));
    }
}
