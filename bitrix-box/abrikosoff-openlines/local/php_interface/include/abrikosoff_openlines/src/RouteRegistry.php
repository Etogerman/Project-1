<?php

namespace Abrikosoff\BitrixBox\OpenLines;

final class RouteRegistry
{
    public const DECISION_DISABLED = 'disabled';

    public const DECISION_ACTIVE = 'active';

    public const DECISION_FALLBACK = 'fallback';

    public const DECISION_BLOCKED = 'blocked';

    private const DEFAULT_STORAGE_DIR = '/home/bitrix/.abrikosoff_openlines';

    private const OPENLINES_CALLBACK_PATH = '/callbacks/bitrix24/openlines';

    private const SCHEMA_VERSION = 1;

    private const HMAC_WINDOW_SECONDS = 300;

    private const MAX_CONNECTORS_PER_OWNER = 500;

    private const MAX_ROUTES_PER_OWNER = 500;

    private const MAX_OWNERS = 100;

    private const MAX_REGISTRY_BYTES = 1048576;

    private const MAX_LOG_BYTES = 5242880;

    /**
     * @var array<string, string>
     */
    private const BUNDLED_COMPONENT_TYPES = [
        'abrikosoff:imconnector.telegram' => 'telegram',
        'abrikosoff:imconnector.max' => 'max',
    ];

    /**
     * Request-scoped validated snapshots keyed by registry file and expected portal.
     *
     * @var array<string, array<string, array{registry: array<string, mixed>|null, error_code: string}>>
     */
    private static array $validatedRegistrySnapshots = [];

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array{decision: string, route: array<string, mixed>|null, error_code: string}
     */
    public static function resolveRuntimeRoute(string $connectorCode, string $lineId, array $runtimeConfig): array
    {
        $connectorCode = trim($connectorCode);
        $lineId = trim($lineId);

        if (! self::runtimeEnabled($runtimeConfig)) {
            return self::runtimeDecision(self::DECISION_DISABLED);
        }

        if ($connectorCode === '' || $lineId === '') {
            return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_invalid_key');
        }

        $registryError = '';
        $registry = self::readRegistry($runtimeConfig, $registryError);
        $routeKey = self::routeKey($connectorCode, $lineId);

        if (! is_array($registry)) {
            $runtimeError = $registryError !== '' ? $registryError : 'route_registry_invalid';

            if (self::registryErrorRequiresFailClosed($registryError)) {
                self::logEvent($runtimeConfig, 'route_registry_invalid', [
                    'connector_code' => $connectorCode,
                    'line_id' => $lineId,
                    'error_code' => $runtimeError,
                ]);

                return self::runtimeDecision(self::DECISION_BLOCKED, null, $runtimeError);
            }

            if (self::transitionFallbackAllowed($runtimeConfig, $routeKey)) {
                self::logEvent($runtimeConfig, 'route_registry_fallback_used', [
                    'connector_code' => $connectorCode,
                    'line_id' => $lineId,
                    'error_code' => $runtimeError,
                ]);

                return self::runtimeDecision(self::DECISION_FALLBACK, null, $runtimeError);
            }

            self::logEvent($runtimeConfig, 'route_registry_invalid', [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'error_code' => $runtimeError,
            ]);

            return self::runtimeDecision(self::DECISION_BLOCKED, null, $runtimeError);
        }

        $route = self::findRoute($registry, $connectorCode, $lineId);

        if (is_array($route) && ($route['active'] ?? false) === true) {
            $connectorTypeConflict = self::routeStaticConnectorTypeConflict(
                $registry,
                $route,
                $runtimeConfig,
            );

            if ($connectorTypeConflict) {
                self::logEvent($runtimeConfig, 'route_registry_invalid', [
                    'connector_code' => $connectorCode,
                    'line_id' => $lineId,
                    'error_code' => 'route_registry_connector_type_conflict',
                ]);

                return self::runtimeDecision(
                    self::DECISION_BLOCKED,
                    null,
                    'route_registry_connector_type_conflict',
                );
            }

            return self::runtimeDecision(self::DECISION_ACTIVE, $route);
        }

        if (self::transitionFallbackAllowed($runtimeConfig, $routeKey)) {
            self::logEvent($runtimeConfig, 'route_registry_fallback_used', [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'error_code' => 'route_registry_miss',
            ]);

            return self::runtimeDecision(self::DECISION_FALLBACK, null, 'route_registry_miss');
        }

        self::logEvent($runtimeConfig, 'route_registry_miss', [
            'connector_code' => $connectorCode,
            'line_id' => $lineId,
            'error_code' => 'route_registry_miss',
        ]);

        return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_miss');
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return list<array<string, mixed>>
     */
    public static function activeRoutesForLine(string $lineId, array $runtimeConfig): array
    {
        $lineId = trim($lineId);

        if ($lineId === '' || ! self::runtimeEnabled($runtimeConfig)) {
            return [];
        }

        $registry = self::readRegistry($runtimeConfig);

        if (! is_array($registry)) {
            return [];
        }

        $matches = [];

        foreach (self::registryRoutes($registry) as $route) {
            if (($route['active'] ?? false) !== true || trim((string) ($route['line_id'] ?? '')) !== $lineId) {
                continue;
            }

            $matches[] = $route;
        }

        return $matches;
    }

    /**
     * @param  list<string>  $staticConnectorCodes
     * @param  list<string>  $staticLineConnectorCodes
     * @param  array<string, mixed>  $runtimeConfig
     * @return array{decision: string, route: array<string, mixed>|null, error_code: string}
     */
    public static function resolveRuntimeLine(
        string $lineId,
        array $staticConnectorCodes,
        array $staticLineConnectorCodes,
        array $runtimeConfig
    ): array {
        $lineId = trim($lineId);
        $staticConnectorCodes = self::normalizeConnectorCodes($staticConnectorCodes);
        $staticLineConnectorCodes = self::normalizeConnectorCodes($staticLineConnectorCodes);

        if (! self::runtimeEnabled($runtimeConfig)) {
            return self::runtimeDecision(self::DECISION_DISABLED);
        }

        if ($lineId === '') {
            return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_invalid_key');
        }

        $registryError = '';
        $registry = self::readRegistry($runtimeConfig, $registryError);

        if (! is_array($registry)) {
            $runtimeError = $registryError !== '' ? $registryError : 'route_registry_invalid';

            if (self::registryErrorRequiresFailClosed($registryError)) {
                self::logEvent($runtimeConfig, 'route_registry_invalid', [
                    'line_id' => $lineId,
                    'error_code' => $runtimeError,
                ]);

                return self::runtimeDecision(self::DECISION_BLOCKED, null, $runtimeError);
            }

            return self::runtimeLineFallbackDecision(
                $lineId,
                $staticLineConnectorCodes,
                $runtimeConfig,
                $runtimeError,
            );
        }

        $matches = [];

        foreach (self::registryRoutes($registry) as $route) {
            if (($route['active'] ?? false) !== true || trim((string) ($route['line_id'] ?? '')) !== $lineId) {
                continue;
            }

            $matches[] = $route;
        }

        if (count($matches) > 1) {
            self::logEvent($runtimeConfig, 'route_registry_invalid', [
                'line_id' => $lineId,
                'error_code' => 'route_registry_duplicate_line_id',
            ]);

            return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_duplicate_line_id');
        }

        if (count($matches) === 1) {
            $route = $matches[0];
            $connectorCode = trim((string) ($route['connector_code'] ?? ''));

            if (self::routeStaticConnectorTypeConflict($registry, $route, $runtimeConfig)) {
                self::logEvent($runtimeConfig, 'route_registry_invalid', [
                    'connector_code' => $connectorCode,
                    'line_id' => $lineId,
                    'error_code' => 'route_registry_connector_type_conflict',
                ]);

                return self::runtimeDecision(
                    self::DECISION_BLOCKED,
                    null,
                    'route_registry_connector_type_conflict',
                );
            }

            if (self::routeHasRegistryConnector($registry, $route)
                || in_array($connectorCode, $staticConnectorCodes, true)
            ) {
                return self::runtimeDecision(self::DECISION_ACTIVE, $route);
            }

            self::logEvent($runtimeConfig, 'route_registry_invalid', [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'error_code' => 'route_registry_connector_missing',
            ]);

            return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_connector_missing');
        }

        return self::runtimeLineFallbackDecision(
            $lineId,
            $staticLineConnectorCodes,
            $runtimeConfig,
            'route_registry_miss',
        );
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<int|string, array{connector_code: string, connector_type: string}>
     */
    public static function activeConnectorDefinitions(array $runtimeConfig): array
    {
        if (! self::runtimeEnabled($runtimeConfig)) {
            return [];
        }

        $registry = self::readRegistry($runtimeConfig);

        if (! is_array($registry)) {
            return [];
        }

        $definitions = [];

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $owner) {
            $connectors = is_array($owner['connectors'] ?? null) ? $owner['connectors'] : [];
            $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];
            $activeConnectorCodes = [];

            foreach ($routes as $route) {
                if (! is_array($route) || ($route['active'] ?? false) !== true) {
                    continue;
                }

                $connectorCode = trim((string) ($route['connector_code'] ?? ''));

                if ($connectorCode !== '') {
                    $activeConnectorCodes[$connectorCode] = true;
                }
            }

            foreach ($connectors as $connectorKey => $connector) {
                if (! is_array($connector)) {
                    continue;
                }

                $connectorCode = trim((string) ($connector['connector_code'] ?? ''));
                $connectorType = trim((string) ($connector['connector_type'] ?? ''));

                if ($connectorCode === ''
                    || (string) $connectorKey !== $connectorCode
                    || ! isset($activeConnectorCodes[$connectorCode])
                    || self::staticConnectorTypeConflicts($runtimeConfig, $connectorCode, $connectorType)
                ) {
                    continue;
                }

                $definitions[$connectorCode] = [
                    'connector_code' => $connectorCode,
                    'connector_type' => $connectorType,
                ];
            }
        }

        ksort($definitions);

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>|null  $endpointConfig
     * @return array{status: int, body: array<string, mixed>}
     */
    public static function handleRequest(array $server, array $headers, string $rawBody, ?array $endpointConfig = null): array
    {
        $endpointConfig ??= self::loadEndpointConfig();
        $method = strtoupper(trim((string) ($server['REQUEST_METHOD'] ?? 'GET')));
        $requestUri = trim((string) ($server['REQUEST_URI'] ?? ''));
        $path = (string) (parse_url($requestUri, PHP_URL_PATH) ?: ($server['SCRIPT_NAME'] ?? ''));
        $query = (string) ($server['QUERY_STRING'] ?? '');
        $requestId = self::headerValue($headers, 'X-ABR-Request-Id');

        $authError = self::authenticate($endpointConfig, $method, $path, $query, $headers, $rawBody);

        if ($authError !== null) {
            self::logEvent($endpointConfig, 'route_registry_signature_failed', [
                'request_id' => $requestId,
                'error_code' => $authError,
            ]);

            return self::response(401, false, $authError);
        }

        $action = self::queryValue($query, 'action');

        if ($method === 'GET' && $action === 'snapshot') {
            $registryError = '';
            $registry = self::readRegistry($endpointConfig, $registryError);

            if ($registry === null) {
                $registryError = $registryError !== '' ? $registryError : 'route_registry_invalid';

                self::logEvent($endpointConfig, 'route_registry_invalid', [
                    'request_id' => $requestId,
                    'error_code' => $registryError,
                ]);

                return self::response(500, false, $registryError);
            }

            return self::response(200, true, '', [
                'registry' => $registry,
                'transition_fallback_routes' => self::transitionFallbackRouteKeys($endpointConfig),
            ]);
        }

        if ($method !== 'POST' || $action !== '') {
            return self::response(404, false, 'route_registry_unknown_action');
        }

        $payload = json_decode($rawBody, true);

        if (! is_array($payload)) {
            return self::response(422, false, 'route_registry_invalid_json');
        }

        $validationError = self::validatePublishPayload($payload, $endpointConfig);

        if ($validationError !== '') {
            if (in_array($validationError, [
                'route_registry_connector_missing',
                'route_registry_connector_type_invalid',
            ], true)) {
                self::logEvent($endpointConfig, 'route_registry_invalid', [
                    'owner_profile_key' => is_scalar($payload['owner_profile_key'] ?? null)
                        ? trim((string) $payload['owner_profile_key'])
                        : '',
                    'request_id' => $requestId,
                    'error_code' => $validationError,
                ]);
            }

            return self::response(422, false, $validationError);
        }

        $publishResult = self::publishOwnerSnapshot($payload, $endpointConfig, $requestId);

        if ($publishResult['error_code'] !== '') {
            $status = in_array($publishResult['error_code'], [
                'route_registry_conflict',
                'route_registry_connector_type_conflict',
            ], true) ? 409 : 422;

            return self::response($status, false, $publishResult['error_code'], [
                'conflicts' => $publishResult['conflicts'],
            ]);
        }

        return self::response(200, true, '', [
            'published_routes' => count(self::normalizePublishRoutes($payload['routes'] ?? [])),
            'owner_profile_key' => (string) $payload['owner_profile_key'],
        ]);
    }

    public static function handleHttpRequest(): void
    {
        $result = self::handleRequest($_SERVER, self::requestHeaders(), (string) file_get_contents('php://input'));

        http_response_code($result['status']);
        header('Content-Type: application/json; charset=UTF-8');

        echo json_encode(
            self::responseBodyForEncoding($result['body']),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ) ?: '{"ok":false}';
    }

    public static function requestSignature(
        string $secret,
        string $method,
        string $path,
        string $query,
        string $timestamp,
        string $requestId,
        string $rawBody
    ): string {
        return 'sha256='.hash_hmac('sha256', self::canonicalString(
            $method,
            $path,
            $query,
            $timestamp,
            $requestId,
            $rawBody,
        ), $secret);
    }

    /**
     * @param  array<string, mixed>  $endpointConfig
     * @param  array<string, string>  $headers
     */
    private static function authenticate(
        array $endpointConfig,
        string $method,
        string $path,
        string $query,
        array $headers,
        string $rawBody
    ): ?string {
        $secret = trim((string) ($endpointConfig['shared_secret'] ?? ''));

        if ($secret === '') {
            return 'route_registry_secret_missing';
        }

        $timestamp = self::headerValue($headers, 'X-ABR-Timestamp');
        $requestId = self::headerValue($headers, 'X-ABR-Request-Id');
        $signature = self::headerValue($headers, 'X-ABR-Signature');

        if (! ctype_digit($timestamp) || abs(time() - (int) $timestamp) > self::HMAC_WINDOW_SECONDS) {
            return 'route_registry_timestamp_invalid';
        }

        if ($requestId === '' || strlen($requestId) > 128) {
            return 'route_registry_request_id_invalid';
        }

        $expected = self::requestSignature($secret, $method, $path, $query, $timestamp, $requestId, $rawBody);

        if (! hash_equals($expected, $signature)) {
            return 'route_registry_signature_invalid';
        }

        return self::markRequestIdSeen($endpointConfig, $requestId, (int) $timestamp);
    }

    /**
     * @param  array<string, mixed>  $endpointConfig
     */
    private static function markRequestIdSeen(array $endpointConfig, string $requestId, int $timestamp): ?string
    {
        $dir = self::storageDir($endpointConfig);

        if (! self::ensurePrivateDirectory($dir)) {
            return 'route_registry_replay_cache_unavailable';
        }

        $cacheFile = $dir.'/route_registry_replay_cache.json';
        $lockFile = $dir.'/route_registry_replay_cache.lock';
        $lock = @fopen($lockFile, 'c+');

        if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
            return 'route_registry_replay_cache_unavailable';
        }

        try {
            $cache = [];

            if (is_file($cacheFile)) {
                $decoded = json_decode((string) file_get_contents($cacheFile), true);
                $cache = is_array($decoded) ? $decoded : [];
            }

            $threshold = time() - self::HMAC_WINDOW_SECONDS;
            $clean = [];

            foreach ($cache as $seenRequestId => $seenAt) {
                if (is_numeric($seenAt) && (int) $seenAt >= $threshold) {
                    $clean[(string) $seenRequestId] = (int) $seenAt;
                }
            }

            if (array_key_exists($requestId, $clean)) {
                return 'route_registry_replay_detected';
            }

            $clean[$requestId] = $timestamp;
            $encoded = json_encode($clean, JSON_UNESCAPED_SLASHES);

            if ($encoded === false || @file_put_contents($cacheFile, $encoded, LOCK_EX) === false) {
                return 'route_registry_replay_cache_unavailable';
            }

            @chmod($cacheFile, 0600);

            return null;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $endpointConfig
     * @return array{error_code: string, conflicts: list<array<string, string>>}
     */
    private static function publishOwnerSnapshot(array $payload, array $endpointConfig, string $requestId): array
    {
        $dir = self::storageDir($endpointConfig);

        if (! self::ensurePrivateDirectory($dir)) {
            return [
                'error_code' => 'route_registry_storage_unavailable',
                'conflicts' => [],
            ];
        }

        $lockFile = $dir.'/route_registry.lock';
        $lock = @fopen($lockFile, 'c+');

        if (! is_resource($lock) || ! flock($lock, LOCK_EX)) {
            return [
                'error_code' => 'route_registry_storage_unavailable',
                'conflicts' => [],
            ];
        }

        try {
            self::forgetValidatedRegistrySnapshots($endpointConfig);
            $registry = self::readRegistry($endpointConfig);

            if (! is_array($registry)) {
                self::logEvent($endpointConfig, 'route_registry_invalid', [
                    'owner_profile_key' => (string) $payload['owner_profile_key'],
                    'request_id' => $requestId,
                    'error_code' => 'route_registry_invalid',
                ]);

                return [
                    'error_code' => 'route_registry_invalid',
                    'conflicts' => [],
                ];
            }

            $ownerKey = (string) $payload['owner_profile_key'];
            $connectors = self::normalizePublishConnectors($payload['connectors'] ?? []);
            $routes = self::normalizePublishRoutes($payload['routes'] ?? []);
            $connectorConflicts = self::detectConnectorTypeConflicts($registry, $ownerKey, $connectors);
            $connectorConflicts = array_merge(
                $connectorConflicts,
                self::detectStaticConnectorTypeConflicts($connectors, $endpointConfig),
            );

            if ($connectorConflicts !== []) {
                self::logEvent($endpointConfig, 'route_registry_conflict', [
                    'owner_profile_key' => $ownerKey,
                    'request_id' => $requestId,
                    'error_code' => 'route_registry_connector_type_conflict',
                ]);

                return [
                    'error_code' => 'route_registry_connector_type_conflict',
                    'conflicts' => $connectorConflicts,
                ];
            }

            $conflicts = self::detectRouteConflicts($registry, $ownerKey, array_keys($routes));
            $lineConflicts = self::detectActiveLineConflicts($registry, $ownerKey, $routes);
            $conflicts = array_merge($conflicts, $lineConflicts);

            if ($conflicts !== []) {
                self::logEvent($endpointConfig, 'route_registry_conflict', [
                    'owner_profile_key' => $ownerKey,
                    'request_id' => $requestId,
                    'error_code' => 'route_registry_conflict',
                ]);

                return [
                    'error_code' => 'route_registry_conflict',
                    'conflicts' => $conflicts,
                ];
            }

            $owners = self::normalizeOwners($registry['owners'] ?? []);

            if (! array_key_exists($ownerKey, $owners) && count($owners) >= self::MAX_OWNERS) {
                return [
                    'error_code' => 'route_registry_too_many_owners',
                    'conflicts' => [],
                ];
            }

            $registry['schema_version'] = self::SCHEMA_VERSION;
            $registry['portal_domain'] = (string) $payload['portal_domain'];
            $registry['updated_at'] = date('c');
            $registry['owners'] = $owners;
            $ownerSnapshot = [
                'owner_profile_key' => $ownerKey,
                'owner_callback_base_url' => self::normalizeCallbackBaseUrl((string) $payload['owner_callback_base_url']),
                'routes' => $routes,
            ];

            if (array_key_exists('connectors', $payload)) {
                $ownerSnapshot['connectors'] = $connectors;
            }

            $registry['owners'][$ownerKey] = $ownerSnapshot;

            $writeError = self::writeRegistry($registry, $endpointConfig);

            if ($writeError !== '') {
                return [
                    'error_code' => $writeError,
                    'conflicts' => [],
                ];
            }

            self::logEvent($endpointConfig, 'route_registry_published', [
                'owner_profile_key' => $ownerKey,
                'callback_host' => self::callbackHost((string) $payload['owner_callback_base_url']),
                'request_id' => $requestId,
            ]);

            return [
                'error_code' => '',
                'conflicts' => [],
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $endpointConfig
     */
    private static function writeRegistry(array $registry, array $endpointConfig): string
    {
        $dir = self::storageDir($endpointConfig);
        $registryFile = $dir.'/route_registry.json';
        $previousFile = $dir.'/route_registry.previous.json';
        $tempFile = $dir.'/route_registry.'.getmypid().'.tmp';
        $encoded = json_encode(
            self::registryForEncoding($registry),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
        );

        if ($encoded === false || strlen($encoded) > self::MAX_REGISTRY_BYTES) {
            return 'route_registry_payload_too_large';
        }

        if (is_file($registryFile) && ! @copy($registryFile, $previousFile)) {
            return 'route_registry_backup_failed';
        }

        if (is_file($previousFile)) {
            @chmod($previousFile, 0600);
        }

        if (@file_put_contents($tempFile, $encoded, LOCK_EX) === false) {
            return 'route_registry_write_failed';
        }

        @chmod($tempFile, 0600);

        if (json_decode((string) file_get_contents($tempFile), true) === null && json_last_error() !== JSON_ERROR_NONE) {
            @unlink($tempFile);

            return 'route_registry_write_failed';
        }

        if (! @rename($tempFile, $registryFile)) {
            @unlink($tempFile);

            return 'route_registry_write_failed';
        }

        @chmod($registryFile, 0600);
        self::forgetValidatedRegistrySnapshots($endpointConfig);

        return '';
    }

    /**
     * Preserve map semantics for numeric-only connector codes at JSON boundaries.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function responseBodyForEncoding(array $body): array
    {
        if (is_array($body['registry'] ?? null)) {
            $body['registry'] = self::registryForEncoding($body['registry']);
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>
     */
    private static function registryForEncoding(array $registry): array
    {
        $owners = self::normalizeOwners($registry['owners'] ?? []);

        foreach ($owners as $ownerKey => $owner) {
            if (array_key_exists('connectors', $owner) && is_array($owner['connectors'])) {
                $owner['connectors'] = (object) $owner['connectors'];
            }

            $owners[$ownerKey] = $owner;
        }

        $registry['owners'] = (object) $owners;

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $endpointConfig
     */
    private static function validatePublishPayload(array $payload, array $endpointConfig): string
    {
        if ((int) ($payload['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return 'route_registry_schema_version_invalid';
        }

        if (! is_scalar($payload['portal_domain'] ?? null)) {
            return 'route_registry_portal_domain_mismatch';
        }

        $portalDomain = trim((string) $payload['portal_domain']);
        $expectedPortalDomain = self::expectedPortalDomain($endpointConfig);

        if ($expectedPortalDomain === '') {
            return 'route_registry_portal_domain_config_missing';
        }

        if ($portalDomain === '' || $portalDomain !== $expectedPortalDomain) {
            return 'route_registry_portal_domain_mismatch';
        }

        if (! is_scalar($payload['owner_profile_key'] ?? null)) {
            return 'route_registry_owner_invalid';
        }

        $ownerKey = trim((string) $payload['owner_profile_key']);

        if (! preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $ownerKey)) {
            return 'route_registry_owner_invalid';
        }

        $allowedOwnerKeys = self::allowedOwnerKeys($endpointConfig);

        if ($allowedOwnerKeys === []) {
            return 'route_registry_owner_allowlist_missing';
        }

        if (! in_array($ownerKey, $allowedOwnerKeys, true)) {
            return 'route_registry_owner_not_allowed';
        }

        if (! is_scalar($payload['owner_callback_base_url'] ?? null)) {
            return 'route_registry_callback_url_invalid';
        }

        $callbackBaseUrl = trim((string) $payload['owner_callback_base_url']);
        $callbackError = self::validateCallbackBaseUrl($callbackBaseUrl, (bool) ($endpointConfig['validate_dns'] ?? true));

        if ($callbackError !== '') {
            return $callbackError;
        }

        if (! isset($payload['routes']) || ! is_array($payload['routes'])) {
            return 'route_registry_routes_invalid';
        }

        $connectors = [];
        $hasConnectorCatalog = array_key_exists('connectors', $payload);

        if ($hasConnectorCatalog) {
            if (! is_array($payload['connectors'])) {
                return 'route_registry_connector_type_invalid';
            }

            if (count($payload['connectors']) > self::MAX_CONNECTORS_PER_OWNER) {
                return 'route_registry_connector_type_invalid';
            }

            foreach ($payload['connectors'] as $connectorKey => $connector) {
                if ((! is_string($connectorKey) && ! is_int($connectorKey))
                    || ! is_array($connector)
                    || array_diff(array_keys($connector), ['connector_code', 'connector_type']) !== []
                    || ! is_scalar($connector['connector_code'] ?? null)
                    || ! is_scalar($connector['connector_type'] ?? null)
                ) {
                    return 'route_registry_connector_type_invalid';
                }

                $normalizedConnectorCode = trim((string) $connector['connector_code']);
                $connectorType = trim((string) $connector['connector_type']);

                if (! self::validConnectorCode($normalizedConnectorCode)
                    || (string) $connectorKey !== $normalizedConnectorCode
                    || ! in_array($connectorType, ['telegram', 'max'], true)
                ) {
                    return 'route_registry_connector_type_invalid';
                }

                $connectors[$normalizedConnectorCode] = $connectorType;
            }
        }

        if (count($payload['routes']) > self::MAX_ROUTES_PER_OWNER) {
            return 'route_registry_too_many_routes';
        }

        $activeLineIds = [];

        foreach ($payload['routes'] as $routeKey => $route) {
            if (! is_string($routeKey)) {
                return 'route_registry_route_key_invalid';
            }

            $routeKey = trim($routeKey);

            if (! self::validRouteKey($routeKey)) {
                return 'route_registry_route_key_invalid';
            }

            if (! is_array($route)) {
                return 'route_registry_route_invalid';
            }

            if (! is_scalar($route['connector_code'] ?? null) || ! is_scalar($route['line_id'] ?? null)) {
                return 'route_registry_route_invalid';
            }

            $connectorCode = trim((string) $route['connector_code']);
            $lineId = trim((string) $route['line_id']);

            if ($connectorCode === '' || $lineId === '' || ! self::validRouteKey(self::routeKey($connectorCode, $lineId))) {
                return 'route_registry_route_invalid';
            }

            if ($routeKey !== self::routeKey($connectorCode, $lineId)) {
                return 'route_registry_route_key_mismatch';
            }

            if (isset($route['line_name']) && ! is_scalar($route['line_name'])) {
                return 'route_registry_route_invalid';
            }

            if (strlen((string) ($route['line_name'] ?? '')) > 255) {
                return 'route_registry_line_name_too_long';
            }

            if (array_key_exists('active', $route) && ! is_bool($route['active'])) {
                return 'route_registry_route_invalid';
            }

            $active = is_bool($route['active'] ?? null) ? $route['active'] : true;

            if ($active) {
                if ($hasConnectorCatalog && ! array_key_exists($connectorCode, $connectors)) {
                    return 'route_registry_connector_missing';
                }

                if (array_key_exists($lineId, $activeLineIds)) {
                    return 'route_registry_duplicate_line_id';
                }

                $activeLineIds[$lineId] = true;
            }
        }

        return '';
    }

    /**
     * @param  mixed  $connectors
     * @return array<int|string, array{connector_code: string, connector_type: string}>
     */
    private static function normalizePublishConnectors($connectors): array
    {
        if (! is_array($connectors)) {
            return [];
        }

        $normalized = [];

        foreach ($connectors as $connectorKey => $connector) {
            if ((! is_string($connectorKey) && ! is_int($connectorKey)) || ! is_array($connector)) {
                continue;
            }

            $connectorCode = trim((string) ($connector['connector_code'] ?? ''));

            if ($connectorCode === ''
                || (string) $connectorKey !== $connectorCode
                || ! self::validConnectorCode($connectorCode)
            ) {
                continue;
            }

            $normalized[$connectorCode] = [
                'connector_code' => $connectorCode,
                'connector_type' => trim((string) ($connector['connector_type'] ?? '')),
            ];
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  mixed  $routes
     * @return array<string, array<string, mixed>>
     */
    private static function normalizePublishRoutes($routes): array
    {
        if (! is_array($routes)) {
            return [];
        }

        $normalized = [];

        foreach ($routes as $routeKey => $route) {
            if (! is_string($routeKey) || ! is_array($route)) {
                continue;
            }

            if (! is_scalar($route['connector_code'] ?? null) || ! is_scalar($route['line_id'] ?? null)) {
                continue;
            }

            $connectorCode = trim((string) $route['connector_code']);
            $lineId = trim((string) $route['line_id']);
            $key = trim($routeKey);

            if ($key === '') {
                continue;
            }

            $normalized[$key] = [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'line_name' => is_scalar($route['line_name'] ?? null) ? trim((string) $route['line_name']) : '',
                'active' => is_bool($route['active'] ?? null) ? $route['active'] : true,
            ];
        }

        return $normalized;
    }

    private static function validateCallbackBaseUrl(string $callbackBaseUrl, bool $validateDns): string
    {
        $callbackBaseUrl = trim($callbackBaseUrl);
        $parts = parse_url($callbackBaseUrl);

        if (! is_array($parts)
            || $callbackBaseUrl === ''
            || strlen($callbackBaseUrl) > 2048
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['query'])
            || isset($parts['fragment'])
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return 'route_registry_callback_url_invalid';
        }

        $host = (string) $parts['host'];

        if (self::isPrivateOrReservedHost($host)) {
            return 'route_registry_callback_host_forbidden';
        }

        if (! $validateDns) {
            return '';
        }

        $ips = self::resolveHostIps($host);

        if ($ips === []) {
            return 'route_registry_callback_dns_failed';
        }

        foreach ($ips as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                return 'route_registry_callback_host_forbidden';
            }
        }

        return '';
    }

    /**
     * @return list<string>
     */
    private static function resolveHostIps(string $host): array
    {
        $ips = [];

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        foreach (dns_get_record($host, DNS_A + DNS_AAAA) ?: [] as $record) {
            foreach (['ip', 'ipv6'] as $key) {
                if (isset($record[$key]) && is_string($record[$key]) && $record[$key] !== '') {
                    $ips[] = $record[$key];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPrivateOrReservedHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPrivateOrReservedIp($host);
        }

        return false;
    }

    private static function isPrivateOrReservedIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  list<string>  $routeKeys
     * @return list<array<string, string>>
     */
    private static function detectRouteConflicts(array $registry, string $ownerKey, array $routeKeys): array
    {
        $conflicts = [];

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $existingOwnerKey => $owner) {
            if ($existingOwnerKey === $ownerKey) {
                continue;
            }

            $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];

            foreach ($routeKeys as $routeKey) {
                if (array_key_exists($routeKey, $routes)) {
                    $conflicts[] = [
                        'route_key' => $routeKey,
                        'owner_profile_key' => $existingOwnerKey,
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<int|string, array{connector_code: string, connector_type: string}>  $connectors
     * @return list<array<string, string>>
     */
    private static function detectConnectorTypeConflicts(array $registry, string $ownerKey, array $connectors): array
    {
        $conflicts = [];

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $existingOwnerKey => $owner) {
            if ($existingOwnerKey === $ownerKey) {
                continue;
            }

            $existingConnectors = is_array($owner['connectors'] ?? null) ? $owner['connectors'] : [];

            foreach ($connectors as $connector) {
                $connectorCode = trim((string) ($connector['connector_code'] ?? ''));
                $existingConnector = $existingConnectors[$connectorCode] ?? null;

                if ($connectorCode === '' || ! is_array($existingConnector)) {
                    continue;
                }

                $connectorType = trim((string) ($connector['connector_type'] ?? ''));
                $existingConnectorType = trim((string) ($existingConnector['connector_type'] ?? ''));

                if ($connectorType === $existingConnectorType) {
                    continue;
                }

                $conflicts[] = [
                    'connector_code' => $connectorCode,
                    'owner_profile_key' => $existingOwnerKey,
                    'connector_type' => $connectorType,
                    'conflict_connector_type' => $existingConnectorType,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<int|string, array{connector_code: string, connector_type: string}>  $connectors
     * @param  array<string, mixed>  $endpointConfig
     * @return list<array<string, string>>
     */
    private static function detectStaticConnectorTypeConflicts(array $connectors, array $endpointConfig): array
    {
        $runtimeConfig = is_array($endpointConfig['runtime_config'] ?? null)
            ? $endpointConfig['runtime_config']
            : self::loadRuntimeConfig();
        $staticConnectorTypes = self::staticConnectorTypes($runtimeConfig);
        $conflicts = [];

        foreach ($connectors as $connector) {
            $connectorCode = trim((string) ($connector['connector_code'] ?? ''));
            $connectorType = trim((string) ($connector['connector_type'] ?? ''));

            if ($connectorCode === ''
                || ! array_key_exists($connectorCode, $staticConnectorTypes)
                || $staticConnectorTypes[$connectorCode] === $connectorType
            ) {
                continue;
            }

            $conflicts[] = [
                'connector_code' => $connectorCode,
                'owner_profile_key' => 'static_config',
                'connector_type' => $connectorType,
                'conflict_connector_type' => $staticConnectorTypes[$connectorCode] !== ''
                    ? $staticConnectorTypes[$connectorCode]
                    : 'unknown',
            ];
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<string, array<string, mixed>>  $routes
     * @return list<array<string, string>>
     */
    private static function detectActiveLineConflicts(array $registry, string $ownerKey, array $routes): array
    {
        $conflicts = [];
        $incomingLineIds = [];

        foreach ($routes as $routeKey => $route) {
            if (($route['active'] ?? false) !== true) {
                continue;
            }

            $lineId = trim((string) ($route['line_id'] ?? ''));

            if ($lineId === '') {
                continue;
            }

            $incomingLineIds[$lineId] = (string) $routeKey;
        }

        if ($incomingLineIds === []) {
            return [];
        }

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $existingOwnerKey => $owner) {
            if ($existingOwnerKey === $ownerKey) {
                continue;
            }

            $existingRoutes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];

            foreach ($existingRoutes as $existingRouteKey => $existingRoute) {
                if (! is_string($existingRouteKey) || ! is_array($existingRoute) || ($existingRoute['active'] ?? false) !== true) {
                    continue;
                }

                $lineId = trim((string) ($existingRoute['line_id'] ?? ''));

                if ($lineId === '' || ! array_key_exists($lineId, $incomingLineIds)) {
                    continue;
                }

                $conflicts[] = [
                    'route_key' => $incomingLineIds[$lineId],
                    'line_id' => $lineId,
                    'owner_profile_key' => $existingOwnerKey,
                    'conflict_route_key' => $existingRouteKey,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<string, mixed>|null
     */
    private static function readRegistry(array $runtimeConfig, ?string &$errorCode = null): ?array
    {
        $errorCode = 'route_registry_invalid';
        $file = self::storageDir($runtimeConfig).'/route_registry.json';
        $expectedPortalDomain = self::expectedPortalDomain($runtimeConfig);

        if (
            isset(self::$validatedRegistrySnapshots[$file])
            && array_key_exists($expectedPortalDomain, self::$validatedRegistrySnapshots[$file])
        ) {
            $snapshot = self::$validatedRegistrySnapshots[$file][$expectedPortalDomain];
            $errorCode = $snapshot['error_code'];

            return $snapshot['registry'];
        }

        if ($expectedPortalDomain === '') {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                null,
                'route_registry_invalid',
                $errorCode,
            );
        }

        if (! is_file($file)) {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                self::emptyRegistry($expectedPortalDomain),
                '',
                $errorCode,
            );
        }

        $raw = @file_get_contents($file);

        if (! is_string($raw) || strlen($raw) > self::MAX_REGISTRY_BYTES) {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                null,
                'route_registry_invalid',
                $errorCode,
            );
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded) || (int) ($decoded['schema_version'] ?? 0) !== self::SCHEMA_VERSION) {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                null,
                'route_registry_invalid',
                $errorCode,
            );
        }

        $registryPortalDomain = trim((string) ($decoded['portal_domain'] ?? ''));

        if ($registryPortalDomain !== $expectedPortalDomain) {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                null,
                'route_registry_invalid',
                $errorCode,
            );
        }

        $storedRegistryError = self::storedRegistryValidationError($decoded);

        if ($storedRegistryError !== '') {
            return self::rememberValidatedRegistrySnapshot(
                $file,
                $expectedPortalDomain,
                null,
                $storedRegistryError,
                $errorCode,
            );
        }

        return self::rememberValidatedRegistrySnapshot(
            $file,
            $expectedPortalDomain,
            $decoded,
            '',
            $errorCode,
        );
    }

    /**
     * @param  array<string, mixed>|null  $registry
     * @return array<string, mixed>|null
     */
    private static function rememberValidatedRegistrySnapshot(
        string $file,
        string $expectedPortalDomain,
        ?array $registry,
        string $snapshotErrorCode,
        ?string &$errorCode
    ): ?array {
        self::$validatedRegistrySnapshots[$file][$expectedPortalDomain] = [
            'registry' => $registry,
            'error_code' => $snapshotErrorCode,
        ];
        $errorCode = $snapshotErrorCode;

        return $registry;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function forgetValidatedRegistrySnapshots(array $config): void
    {
        unset(self::$validatedRegistrySnapshots[self::storageDir($config).'/route_registry.json']);
    }

    /**
     * @param  array<string, mixed>  $registry
     */
    private static function storedRegistryValidationError(array $registry): string
    {
        if (! isset($registry['owners']) || ! is_array($registry['owners']) || count($registry['owners']) > self::MAX_OWNERS) {
            return 'route_registry_invalid';
        }

        $seenRouteKeys = [];
        $seenActiveLineIds = [];
        $seenConnectorTypes = [];

        foreach ($registry['owners'] as $ownerKey => $owner) {
            if (! is_string($ownerKey) || ! preg_match('/^[a-zA-Z0-9._-]{1,128}$/', $ownerKey) || ! is_array($owner)) {
                return 'route_registry_invalid';
            }

            if (! is_scalar($owner['owner_profile_key'] ?? null) || trim((string) $owner['owner_profile_key']) !== $ownerKey) {
                return 'route_registry_invalid';
            }

            if (! is_scalar($owner['owner_callback_base_url'] ?? null)
                || self::validateCallbackBaseUrl((string) $owner['owner_callback_base_url'], false) !== ''
            ) {
                return 'route_registry_invalid';
            }

            if (! isset($owner['routes']) || ! is_array($owner['routes']) || count($owner['routes']) > self::MAX_ROUTES_PER_OWNER) {
                return 'route_registry_invalid';
            }

            $connectors = [];
            $hasConnectorCatalog = array_key_exists('connectors', $owner);

            if ($hasConnectorCatalog) {
                if (! is_array($owner['connectors']) || count($owner['connectors']) > self::MAX_CONNECTORS_PER_OWNER) {
                    return 'route_registry_connector_type_invalid';
                }

                foreach ($owner['connectors'] as $connectorKey => $connector) {
                    if (! self::storedConnectorIsValid($connectorKey, $connector)) {
                        return 'route_registry_connector_type_invalid';
                    }

                    $connectorCode = trim((string) $connector['connector_code']);
                    $connectorType = trim((string) $connector['connector_type']);

                    if (isset($seenConnectorTypes[$connectorCode])
                        && $seenConnectorTypes[$connectorCode] !== $connectorType
                    ) {
                        return 'route_registry_connector_type_conflict';
                    }

                    $connectors[$connectorCode] = true;
                    $seenConnectorTypes[$connectorCode] = $connectorType;
                }
            }

            foreach ($owner['routes'] as $routeKey => $route) {
                if (! self::storedRouteIsValid($routeKey, $route)) {
                    return 'route_registry_invalid';
                }

                if (array_key_exists($routeKey, $seenRouteKeys)) {
                    return 'route_registry_conflict';
                }

                $seenRouteKeys[$routeKey] = true;

                if (($route['active'] ?? false) === true) {
                    $connectorCode = trim((string) ($route['connector_code'] ?? ''));

                    if ($hasConnectorCatalog && ! isset($connectors[$connectorCode])) {
                        return 'route_registry_connector_missing';
                    }

                    $lineId = trim((string) ($route['line_id'] ?? ''));

                    if (array_key_exists($lineId, $seenActiveLineIds)) {
                        return 'route_registry_duplicate_line_id';
                    }

                    $seenActiveLineIds[$lineId] = true;
                }
            }
        }

        return '';
    }

    /**
     * @param  mixed  $connectorCode
     * @param  mixed  $connector
     */
    private static function storedConnectorIsValid($connectorCode, $connector): bool
    {
        if ((! is_string($connectorCode) && ! is_int($connectorCode))
            || ! is_array($connector)
            || array_diff(array_keys($connector), ['connector_code', 'connector_type']) !== []
            || ! is_scalar($connector['connector_code'] ?? null)
            || ! is_scalar($connector['connector_type'] ?? null)
        ) {
            return false;
        }

        $normalizedConnectorCode = trim((string) $connector['connector_code']);

        return self::validConnectorCode($normalizedConnectorCode)
            && $normalizedConnectorCode === (string) $connectorCode
            && in_array(trim((string) $connector['connector_type']), ['telegram', 'max'], true);
    }

    /**
     * @param  mixed  $routeKey
     * @param  mixed  $route
     */
    private static function storedRouteIsValid($routeKey, $route): bool
    {
        if (! is_string($routeKey) || ! self::validRouteKey($routeKey) || ! is_array($route)) {
            return false;
        }

        if (! is_scalar($route['connector_code'] ?? null) || ! is_scalar($route['line_id'] ?? null)) {
            return false;
        }

        $connectorCode = trim((string) $route['connector_code']);
        $lineId = trim((string) $route['line_id']);

        if ($connectorCode === '' || $lineId === '' || $routeKey !== self::routeKey($connectorCode, $lineId)) {
            return false;
        }

        if (! array_key_exists('active', $route) || ! is_bool($route['active'])) {
            return false;
        }

        if (isset($route['line_name']) && ! is_scalar($route['line_name'])) {
            return false;
        }

        return strlen((string) ($route['line_name'] ?? '')) <= 255;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return list<array<string, mixed>>
     */
    private static function registryRoutes(array $registry): array
    {
        $routes = [];

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $ownerKey => $owner) {
            $ownerCallbackBaseUrl = self::normalizeCallbackBaseUrl((string) ($owner['owner_callback_base_url'] ?? ''));
            $ownerRoutes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];

            foreach ($ownerRoutes as $route) {
                if (! is_array($route)) {
                    continue;
                }

                $routes[] = $route + [
                    'owner_profile_key' => $ownerKey,
                    'owner_callback_base_url' => $ownerCallbackBaseUrl,
                ];
            }
        }

        return $routes;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $route
     */
    private static function routeHasRegistryConnector(array $registry, array $route): bool
    {
        $ownerKey = trim((string) ($route['owner_profile_key'] ?? ''));
        $connectorCode = trim((string) ($route['connector_code'] ?? ''));
        $owners = self::normalizeOwners($registry['owners'] ?? []);
        $owner = $owners[$ownerKey] ?? null;

        if (! is_array($owner) || ! is_array($owner['connectors'] ?? null)) {
            return false;
        }

        return is_array($owner['connectors'][$connectorCode] ?? null);
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $route
     */
    private static function routeRegistryConnectorType(array $registry, array $route): string
    {
        $ownerKey = trim((string) ($route['owner_profile_key'] ?? ''));
        $connectorCode = trim((string) ($route['connector_code'] ?? ''));
        $owners = self::normalizeOwners($registry['owners'] ?? []);
        $owner = $owners[$ownerKey] ?? null;
        $connector = is_array($owner) && is_array($owner['connectors'] ?? null)
            ? ($owner['connectors'][$connectorCode] ?? null)
            : null;

        return is_array($connector)
            ? trim((string) ($connector['connector_type'] ?? ''))
            : '';
    }

    /**
     * @param  array<string, mixed>  $registry
     * @param  array<string, mixed>  $route
     * @param  array<string, mixed>  $runtimeConfig
     */
    private static function routeStaticConnectorTypeConflict(
        array $registry,
        array $route,
        array $runtimeConfig
    ): bool {
        $connectorCode = trim((string) ($route['connector_code'] ?? ''));
        $connectorType = self::routeRegistryConnectorType($registry, $route);

        return $connectorType !== ''
            && self::staticConnectorTypeConflicts($runtimeConfig, $connectorCode, $connectorType);
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private static function staticConnectorTypeConflicts(
        array $runtimeConfig,
        string $connectorCode,
        string $connectorType
    ): bool {
        $staticConnectorTypes = self::staticConnectorTypes($runtimeConfig);

        return array_key_exists($connectorCode, $staticConnectorTypes)
            && $staticConnectorTypes[$connectorCode] !== $connectorType;
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     * @return array<int|string, string>
     */
    private static function staticConnectorTypes(array $runtimeConfig): array
    {
        $connectors = self::arrayGet($runtimeConfig, 'connectors', []);

        if (! is_array($connectors)) {
            return [];
        }

        $types = [];

        foreach ($connectors as $connectorKey => $connector) {
            $connectorCode = trim((string) $connectorKey);

            if ($connectorCode === '' || ! self::validConnectorCode($connectorCode) || ! is_array($connector)) {
                continue;
            }

            $component = trim((string) ($connector['component'] ?? ''));
            $types[$connectorCode] = self::BUNDLED_COMPONENT_TYPES[$component] ?? '';
        }

        return $types;
    }

    /**
     * @param  array<string, mixed>  $registry
     * @return array<string, mixed>|null
     */
    private static function findRoute(array $registry, string $connectorCode, string $lineId): ?array
    {
        $routeKey = self::routeKey($connectorCode, $lineId);

        foreach (self::normalizeOwners($registry['owners'] ?? []) as $ownerKey => $owner) {
            $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];
            $route = $routes[$routeKey] ?? null;

            if (! is_array($route)) {
                continue;
            }

            return $route + [
                'owner_profile_key' => $ownerKey,
                'owner_callback_base_url' => self::normalizeCallbackBaseUrl((string) ($owner['owner_callback_base_url'] ?? '')),
            ];
        }

        return null;
    }

    /**
     * @param  mixed  $owners
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeOwners($owners): array
    {
        if (! is_array($owners)) {
            return [];
        }

        return array_slice(array_filter($owners, 'is_array'), 0, self::MAX_OWNERS, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyRegistry(string $portalDomain): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'portal_domain' => $portalDomain,
            'updated_at' => '',
            'owners' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private static function runtimeEnabled(array $runtimeConfig): bool
    {
        return (bool) self::arrayGet($runtimeConfig, 'route_registry.enabled', false);
    }

    /**
     * @param  array<string, mixed>  $runtimeConfig
     */
    private static function transitionFallbackAllowed(array $runtimeConfig, string $routeKey): bool
    {
        $routes = self::transitionFallbackRouteKeys($runtimeConfig);

        return in_array('*', $routes, true) || in_array($routeKey, $routes, true);
    }

    /**
     * @param  list<string>  $staticConnectorCodes
     * @param  array<string, mixed>  $runtimeConfig
     * @return array{decision: string, route: array<string, mixed>|null, error_code: string}
     */
    private static function runtimeLineFallbackDecision(
        string $lineId,
        array $staticConnectorCodes,
        array $runtimeConfig,
        string $errorCode
    ): array {
        $fallbackConnectorCodes = [];

        foreach ($staticConnectorCodes as $connectorCode) {
            if (self::transitionFallbackAllowed($runtimeConfig, self::routeKey($connectorCode, $lineId))) {
                $fallbackConnectorCodes[] = $connectorCode;
            }
        }

        if (count($fallbackConnectorCodes) === 1) {
            $connectorCode = $fallbackConnectorCodes[0];

            self::logEvent($runtimeConfig, 'route_registry_fallback_used', [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'error_code' => $errorCode,
            ]);

            return self::runtimeDecision(self::DECISION_FALLBACK, [
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
            ], $errorCode);
        }

        if (count($fallbackConnectorCodes) > 1) {
            self::logEvent($runtimeConfig, 'route_registry_invalid', [
                'line_id' => $lineId,
                'error_code' => 'route_registry_duplicate_line_id',
            ]);

            return self::runtimeDecision(self::DECISION_BLOCKED, null, 'route_registry_duplicate_line_id');
        }

        $event = $errorCode === 'route_registry_invalid'
            ? 'route_registry_invalid'
            : 'route_registry_miss';
        $payload = [
            'line_id' => $lineId,
            'error_code' => $errorCode,
        ];

        if (count($staticConnectorCodes) === 1) {
            $payload['connector_code'] = $staticConnectorCodes[0];
        }

        self::logEvent($runtimeConfig, $event, $payload);

        return self::runtimeDecision(self::DECISION_BLOCKED, null, $errorCode);
    }

    private static function registryErrorRequiresFailClosed(string $errorCode): bool
    {
        return in_array($errorCode, [
            'route_registry_conflict',
            'route_registry_connector_missing',
            'route_registry_connector_type_conflict',
            'route_registry_connector_type_invalid',
            'route_registry_duplicate_line_id',
        ], true);
    }

    /**
     * @param  list<string>  $connectorCodes
     * @return list<string>
     */
    private static function normalizeConnectorCodes(array $connectorCodes): array
    {
        $normalized = [];

        foreach ($connectorCodes as $connectorCode) {
            $connectorCode = trim((string) $connectorCode);

            if (self::validConnectorCode($connectorCode)) {
                $normalized[$connectorCode] = true;
            }
        }

        $connectorCodes = array_keys($normalized);
        sort($connectorCodes);

        return $connectorCodes;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function transitionFallbackRouteKeys(array $config): array
    {
        $routes = $config['transition_fallback_routes'] ?? self::arrayGet($config, 'route_registry.transition_fallback_routes', []);

        if (! is_array($routes)) {
            return [];
        }

        $routeKeys = [];

        foreach ($routes as $routeKey) {
            $routeKey = trim((string) $routeKey);

            if ($routeKey === '*' || self::validRouteKey($routeKey)) {
                $routeKeys[$routeKey] = true;
            }
        }

        $routeKeys = array_keys($routeKeys);
        sort($routeKeys);

        return $routeKeys;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function storageDir(array $config): string
    {
        $configured = trim((string) ($config['storage_dir'] ?? self::arrayGet($config, 'route_registry.storage_dir', '')));

        return $configured !== '' ? rtrim($configured, '/') : self::DEFAULT_STORAGE_DIR;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private static function expectedPortalDomain(array $config): string
    {
        return trim((string) ($config['expected_portal_domain'] ?? self::arrayGet($config, 'auth.portal_domain', '')));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    private static function allowedOwnerKeys(array $config): array
    {
        $keys = $config['allowed_owner_profile_keys'] ?? [];

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $keys), static fn (string $key): bool => $key !== ''));
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadEndpointConfig(): array
    {
        $file = self::DEFAULT_STORAGE_DIR.'/route_registry_config.php';

        if (! is_file($file)) {
            return [];
        }

        $config = require $file;

        $config = is_array($config) ? $config : [];
        $runtimeConfig = self::loadRuntimeConfig();
        $runtimeFallbackRoutes = self::arrayGet($runtimeConfig, 'route_registry.transition_fallback_routes', []);

        if (is_array($runtimeFallbackRoutes)
            && $runtimeFallbackRoutes !== []
            && ! isset($config['transition_fallback_routes'])
            && ! isset($config['route_registry']['transition_fallback_routes'])) {
            $config['route_registry'] = is_array($config['route_registry'] ?? null) ? $config['route_registry'] : [];
            $config['route_registry']['transition_fallback_routes'] = $runtimeFallbackRoutes;
        }

        return $config;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadRuntimeConfig(): array
    {
        $file = dirname(__DIR__).'/config.php';

        if (! is_file($file)) {
            return [];
        }

        $config = require $file;

        return is_array($config) ? $config : [];
    }

    /**
     * @return array<string, string>
     */
    private static function requestHeaders(): array
    {
        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            return is_array($headers) ? array_map('strval', $headers) : [];
        }

        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'HTTP_')) {
                continue;
            }

            $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$header] = (string) $value;
        }

        return $headers;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private static function headerValue(array $headers, string $name): string
    {
        foreach ($headers as $headerName => $value) {
            if (strtolower($headerName) === strtolower($name)) {
                return trim((string) $value);
            }
        }

        return '';
    }

    private static function queryValue(string $query, string $key): string
    {
        parse_str($query, $values);
        $value = $values[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function routeKey(string $connectorCode, string $lineId): string
    {
        $connectorCode = trim($connectorCode);
        $lineId = trim($lineId);

        return $connectorCode === '' || $lineId === '' ? '' : $connectorCode.':'.$lineId;
    }

    private static function validRouteKey(string $routeKey): bool
    {
        $parts = explode(':', $routeKey, 2);

        if (count($parts) !== 2) {
            return false;
        }

        [$connectorCode, $lineId] = $parts;

        return self::validConnectorCode($connectorCode)
            && preg_match('/^[0-9]{1,64}$/', $lineId) === 1;
    }

    private static function validConnectorCode(string $connectorCode): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]{1,64}$/', trim($connectorCode)) === 1;
    }

    private static function normalizeCallbackBaseUrl(string $callbackBaseUrl): string
    {
        $callbackBaseUrl = trim($callbackBaseUrl);

        if ($callbackBaseUrl === '') {
            return '';
        }

        $parts = parse_url($callbackBaseUrl);

        if (! is_array($parts) || trim((string) ($parts['scheme'] ?? '')) === '' || trim((string) ($parts['host'] ?? '')) === '') {
            return rtrim($callbackBaseUrl, '/');
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '['.$host.']';
        }

        $port = isset($parts['port']) ? ':'.(int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');

        if (rtrim($path, '/') === self::OPENLINES_CALLBACK_PATH) {
            $path = '';
        }

        $normalized = $scheme.'://'.$host.$port;

        if ($path !== '' && $path !== '/') {
            $normalized .= '/'.trim($path, '/');
        }

        return rtrim($normalized, '/');
    }

    private static function callbackHost(string $callbackBaseUrl): string
    {
        $host = parse_url($callbackBaseUrl, PHP_URL_HOST);

        return is_string($host) ? $host : '';
    }

    private static function canonicalString(
        string $method,
        string $path,
        string $query,
        string $timestamp,
        string $requestId,
        string $rawBody
    ): string {
        return strtoupper(trim($method))."\n"
            .$path."\n"
            .$query."\n"
            .$timestamp."\n"
            .$requestId."\n"
            .hash('sha256', $rawBody);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{decision: string, route: array<string, mixed>|null, error_code: string}
     */
    private static function runtimeDecision(string $decision, ?array $payload = null, string $errorCode = ''): array
    {
        return [
            'decision' => $decision,
            'route' => $payload,
            'error_code' => $errorCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private static function logEvent(array $config, string $event, array $payload = []): void
    {
        $dir = self::storageDir($config);

        if (! self::ensurePrivateDirectory($dir)) {
            return;
        }

        $file = $dir.'/route_registry_events.log';

        if (is_file($file) && filesize($file) !== false && filesize($file) > self::MAX_LOG_BYTES) {
            @rename($file, $file.'.1');
        }

        $safePayload = array_intersect_key($payload, array_flip([
            'connector_code',
            'line_id',
            'owner_profile_key',
            'callback_host',
            'request_id',
            'error_code',
        ]));
        $line = json_encode([
            'ts' => date('c'),
            'event' => $event,
            'payload' => $safePayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            @file_put_contents($file, $line."\n", FILE_APPEND | LOCK_EX);
            @chmod($file, 0600);
        }
    }

    private static function ensurePrivateDirectory(string $dir): bool
    {
        if (is_dir($dir)) {
            return is_writable($dir);
        }

        return @mkdir($dir, 0700, true);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return mixed
     */
    private static function arrayGet(array $source, string $path, $default = null)
    {
        $value = $source;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{status: int, body: array<string, mixed>}
     */
    private static function response(int $status, bool $ok, string $errorCode = '', array $extra = []): array
    {
        return [
            'status' => $status,
            'body' => [
                'ok' => $ok,
                'error_code' => $errorCode,
            ] + $extra,
        ];
    }
}
