<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use JsonException;
use Throwable;

final class Bitrix24OpenLineIdCompatibilityService
{
    private const FILES = [
        'current_registry' => 'route_registry.json',
        'previous_registry' => 'route_registry.previous.json',
        'active_leases' => 'route_registry_line_leases.json',
    ];

    public function __construct(
        private readonly Bitrix24OpenLinesRouteRegistrySnapshotLock $snapshotLock,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preflight(string $storageDirectory): array
    {
        $storageDirectory = $this->validatedStorageDirectory($storageDirectory);

        return $this->runWithSnapshotLock(
            fn (): array => $this->withRegistryLock(
                $storageDirectory,
                LOCK_SH,
                fn (): array => $this->preflightUnlocked($storageDirectory),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightUnlocked(
        string $storageDirectory,
        bool $lockDatabaseRows = false,
    ): array {
        if ($lockDatabaseRows) {
            $this->lockDatabaseSourceRows();
        }

        $invalid = [];
        $databaseEntries = $this->databaseEntries();
        $profileDatabaseEntries = $this->profileDatabaseEntries();
        $entries = [...$databaseEntries, ...$profileDatabaseEntries];
        $sourceCounts = [
            'database' => count($databaseEntries),
            'profile_database' => count($profileDatabaseEntries),
        ];
        $documents = [];
        $registryPortal = null;

        foreach ($entries as $entry) {
            if ($entry['canonical_line_id'] === null) {
                $invalid[] = [
                    'source' => $entry['source'],
                    'locator' => $entry['locator'],
                    'line_id' => $entry['line_id'],
                ];
            }
        }

        foreach (self::FILES as $source => $fileName) {
            $path = $storageDirectory.DIRECTORY_SEPARATOR.$fileName;
            $document = $this->readOptionalJson($path, $source, $invalid);
            $documents[$source] = $document;

            if (in_array($source, ['current_registry', 'previous_registry'], true)) {
                $portal = $this->normalizePortal((string) ($document['portal_domain'] ?? ''));
                $registryPortal ??= $portal !== '' ? $portal : null;
                $fileEntries = $this->registryEntries(
                    $source,
                    $document,
                    $portal,
                    is_file($path),
                    $invalid,
                );
            } else {
                $fileEntries = $this->leaseEntries(
                    $source,
                    $document,
                    $registryPortal ?? '',
                    $invalid,
                );
            }

            $sourceCounts[$source] = count($fileEntries);
            array_push($entries, ...$fileEntries);
        }

        [$migrations, $collisions] = $this->analyzeEntries($entries);

        return [
            'schema_version' => 1,
            'generated_at' => now()->toAtomString(),
            'storage_directory' => $storageDirectory,
            'ready' => $invalid === [] && $collisions === [],
            'source_counts' => $sourceCounts,
            'entries' => $entries,
            'migrations' => $migrations,
            'collisions' => $collisions,
            'invalid' => $invalid,
            'source_hashes' => $this->sourceHashes($storageDirectory, $entries),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function preflightArtifact(string $storageDirectory, string $artifactPath): array
    {
        $storageDirectory = $this->validatedStorageDirectory($storageDirectory);
        $artifactPath = $this->validatedArtifactPath($storageDirectory, $artifactPath);

        return $this->runWithSnapshotLock(
            fn (): array => $this->withRegistryLock(
                $storageDirectory,
                LOCK_SH,
                fn (): array => DB::transaction(
                    fn (): array => $this->writeArtifact(
                        $artifactPath,
                        $this->preflightUnlocked($storageDirectory, lockDatabaseRows: true) + [
                            'migration_applied' => false,
                            'backup_files' => [],
                        ],
                    ),
                ),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function migrate(string $storageDirectory, string $artifactPath): array
    {
        $storageDirectory = $this->validatedStorageDirectory($storageDirectory);
        $artifactPath = $this->validatedArtifactPath($storageDirectory, $artifactPath);

        return $this->runWithSnapshotLock(
            fn (): array => $this->withRegistryLock(
                $storageDirectory,
                LOCK_EX,
                fn (): array => $this->migrateUnlocked($storageDirectory, $artifactPath),
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function migrateUnlocked(string $storageDirectory, string $artifactPath): array
    {
        $report = $this->preflightUnlocked($storageDirectory);

        if (($report['ready'] ?? false) !== true) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_compatibility_blocked',
                'Compatibility migration заблокирована invalid-значениями или collision.',
            );
        }

        $migrations = is_array($report['migrations'] ?? null) ? $report['migrations'] : [];

        if ($migrations === []) {
            return DB::transaction(function () use ($artifactPath, $storageDirectory): array {
                $lockedReport = $this->preflightUnlocked(
                    $storageDirectory,
                    lockDatabaseRows: true,
                );

                if (($lockedReport['ready'] ?? false) !== true
                    || ($lockedReport['migrations'] ?? []) !== []
                ) {
                    throw new Bitrix24OpenLineIdCompatibilityException(
                        'openlines_line_id_sources_changed',
                        'Источники LINE_ID изменились после compatibility preflight.',
                    );
                }

                return $this->writeArtifact($artifactPath, $lockedReport + [
                    'migration_applied' => false,
                    'backup_files' => [],
                ]);
            });
        }

        $backupSuffix = '.backup.'.now()->format('YmdHis').'.'.bin2hex(random_bytes(4));
        $backupFiles = [];
        $originalFiles = [];
        $artifactPath = trim($artifactPath);
        $artifactExisted = is_file($artifactPath);
        $originalArtifact = $artifactExisted ? (string) file_get_contents($artifactPath) : null;

        foreach (self::FILES as $source => $fileName) {
            $path = rtrim($storageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;

            if (! is_file($path)) {
                continue;
            }

            $backupPath = $path.$backupSuffix;

            if (! copy($path, $backupPath) || ! @chmod($backupPath, 0600)) {
                foreach ($backupFiles as $createdBackupPath) {
                    @unlink($createdBackupPath);
                }

                @unlink($backupPath);

                throw new Bitrix24OpenLineIdCompatibilityException(
                    'openlines_line_id_backup_failed',
                    sprintf('Не удалось создать backup для %s.', $fileName),
                );
            }

            $backupFiles[$source] = $backupPath;
            $originalFiles[$path] = (string) file_get_contents($path);
        }

        try {
            return DB::transaction(function () use (
                $artifactPath,
                $backupFiles,
                $report,
                $storageDirectory,
            ): array {
                $lockedReport = $this->preflightUnlocked(
                    $storageDirectory,
                    lockDatabaseRows: true,
                );

                if (($lockedReport['ready'] ?? false) !== true
                    || ($lockedReport['source_hashes'] ?? null) !== ($report['source_hashes'] ?? null)
                    || ($lockedReport['entries'] ?? null) !== ($report['entries'] ?? null)
                    || ($lockedReport['migrations'] ?? null) !== ($report['migrations'] ?? null)
                ) {
                    throw new Bitrix24OpenLineIdCompatibilityException(
                        'openlines_line_id_sources_changed',
                        'Источники LINE_ID изменились после compatibility preflight.',
                    );
                }

                foreach ($lockedReport['migrations'] as $migration) {
                    if (($migration['source'] ?? '') === 'database') {
                        $this->migrateRouteDatabaseEntry($migration);
                    } elseif (($migration['source'] ?? '') === 'profile_database') {
                        $this->migrateProfileDatabaseEntry($migration);
                    }
                }

                $this->migrateRegistryFiles($storageDirectory);

                $after = $this->preflightUnlocked(
                    $storageDirectory,
                    lockDatabaseRows: true,
                );

                if (($after['ready'] ?? false) !== true || ($after['migrations'] ?? []) !== []) {
                    throw new Bitrix24OpenLineIdCompatibilityException(
                        'openlines_line_id_migration_incomplete',
                        'Compatibility migration не привела все источники к каноническому LINE_ID.',
                    );
                }

                return $this->writeArtifact($artifactPath, $after + [
                    'migration_applied' => true,
                    'backup_files' => $backupFiles,
                    'preflight_source_hashes' => $report['source_hashes'],
                ]);
            });
        } catch (Throwable $exception) {
            foreach ($originalFiles as $path => $contents) {
                $this->atomicWrite($path, $contents);
            }

            if ($artifactExisted && is_string($originalArtifact)) {
                $this->atomicWrite($artifactPath, $originalArtifact);
            } elseif (is_file($artifactPath)) {
                unlink($artifactPath);
            }

            throw $exception;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function databaseEntries(): array
    {
        return Bitrix24OpenLineRoute::query()
            ->with(['callbackOwner:id,owner_key,callback_base_url'])
            ->whereNotNull('line_id')
            ->orderBy('id')
            ->get()
            ->map(function (Bitrix24OpenLineRoute $route): array {
                $lineId = (string) $route->line_id;
                $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
                    (string) $route->channel_type,
                ) ?? '';

                return $this->entry(
                    source: 'database',
                    locator: 'route:'.$route->id,
                    recordId: (int) $route->id,
                    portal: (string) $route->portal_domain,
                    lineId: $lineId,
                    identity: implode('|', [
                        (string) $route->callbackOwner?->owner_key,
                        rtrim((string) $route->callbackOwner?->callback_base_url, '/'),
                        (string) $route->connector_code,
                        $connectorType,
                    ]),
                    claiming: $route->claimsExternalLine(),
                    usable: $route->isUsable(),
                );
            })
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function profileDatabaseEntries(): array
    {
        return Bitrix24Profile::query()
            ->with(['callbackOwners:id,bitrix24_profile_id,owner_key,callback_base_url'])
            ->where(function ($query): void {
                $query
                    ->whereNotNull('telegram_line_id')
                    ->orWhereNotNull('max_line_id');
            })
            ->orderBy('id')
            ->get()
            ->flatMap(function (Bitrix24Profile $profile): array {
                $entries = [];

                foreach ([
                    'telegram_line_id' => [
                        'connector_code' => (string) $profile->telegram_connector_code,
                        'connector_type' => Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM,
                    ],
                    'max_line_id' => [
                        'connector_code' => (string) $profile->max_connector_code,
                        'connector_type' => Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX,
                    ],
                ] as $field => $connector) {
                    $lineId = (string) $profile->{$field};

                    if (trim($lineId) === '') {
                        continue;
                    }

                    [$ownerKey, $callbackBaseUrl] = $this->profileOwnerIdentity($profile);
                    $entries[] = $this->entry(
                        source: 'profile_database',
                        locator: sprintf('profile:%d/%s', $profile->id, $field),
                        recordId: (int) $profile->id,
                        portal: (string) $profile->portal_domain,
                        lineId: $lineId,
                        identity: implode('|', [
                            $ownerKey,
                            $callbackBaseUrl,
                            $connector['connector_code'],
                            $connector['connector_type'],
                        ]),
                        claiming: true,
                        usable: false,
                        recordField: $field,
                    );
                }

                return $entries;
            })
            ->values()
            ->all();
    }

    /**
     * Lock every database row that can contribute to a compatibility snapshot.
     *
     * Relations are loaded by separate queries, so locking only the route or
     * profile row does not protect callback-owner identity. Keep one
     * deterministic lock order for all sources before reading any relation.
     */
    private function lockDatabaseSourceRows(): void
    {
        Bitrix24Profile::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        Bitrix24OpenLineRoute::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        Bitrix24CallbackOwner::query()
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
    }

    private function runWithSnapshotLock(Closure $callback): mixed
    {
        try {
            return $this->snapshotLock->run($callback);
        } catch (LockTimeoutException $exception) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_snapshot_busy',
                Bitrix24OpenLinesRouteRegistrySnapshotLock::BUSY_MESSAGE,
                $exception,
            );
        }
    }

    /**
     * @return array{string, string}
     */
    private function profileOwnerIdentity(Bitrix24Profile $profile): array
    {
        $profileCallbackBaseUrl = rtrim(trim((string) $profile->callback_base_url), '/');
        $matchingOwners = $profile->callbackOwners
            ->filter(
                fn ($owner): bool => rtrim(trim((string) $owner->callback_base_url), '/')
                    === $profileCallbackBaseUrl,
            )
            ->values();

        if ($matchingOwners->count() === 1) {
            $owner = $matchingOwners->first();

            return [
                (string) $owner->owner_key,
                rtrim((string) $owner->callback_base_url, '/'),
            ];
        }

        return [
            'profile:'.(string) $profile->profile_key,
            $profileCallbackBaseUrl,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, string>>  $invalid
     * @return list<array<string, mixed>>
     */
    private function registryEntries(
        string $source,
        array $document,
        string $portal,
        bool $sourceExists,
        array &$invalid,
    ): array {
        $entries = [];
        $owners = $document['owners'] ?? null;

        if (! is_array($owners)) {
            if ($sourceExists) {
                $invalid[] = [
                    'source' => $source,
                    'locator' => 'owners',
                    'line_id' => 'invalid_owners',
                ];
            }

            return [];
        }

        foreach ($owners as $ownerKey => $owner) {
            if (! is_array($owner)) {
                $invalid[] = [
                    'source' => $source,
                    'locator' => 'owner:'.(string) $ownerKey,
                    'line_id' => 'invalid_owner',
                ];

                continue;
            }

            $routes = $owner['routes'] ?? null;

            if (! is_array($routes)) {
                $invalid[] = [
                    'source' => $source,
                    'locator' => 'owner:'.(string) $ownerKey.'/routes',
                    'line_id' => 'invalid_routes',
                ];

                continue;
            }

            $connectors = is_array($owner['connectors'] ?? null) ? $owner['connectors'] : [];

            foreach ($routes as $routeKey => $route) {
                if (! is_array($route)
                    || ! is_scalar($route['line_id'] ?? null)
                    || ! is_scalar($route['connector_code'] ?? null)
                ) {
                    $invalid[] = [
                        'source' => $source,
                        'locator' => sprintf(
                            'owner:%s/route:%s',
                            (string) $ownerKey,
                            (string) $routeKey,
                        ),
                        'line_id' => 'invalid_route',
                    ];

                    continue;
                }

                $lineId = (string) $route['line_id'];
                $connectorCode = trim((string) $route['connector_code']);
                $connector = is_array($connectors[$connectorCode] ?? null)
                    ? $connectors[$connectorCode]
                    : [];
                $entry = $this->entry(
                    source: $source,
                    locator: sprintf('owner:%s/route:%s', (string) $ownerKey, (string) $routeKey),
                    recordId: null,
                    portal: $portal,
                    lineId: $lineId,
                    identity: implode('|', [
                        (string) $ownerKey,
                        rtrim((string) ($owner['owner_callback_base_url'] ?? ''), '/'),
                        $connectorCode,
                        trim((string) ($connector['connector_type'] ?? $route['connector_type'] ?? '')),
                    ]),
                    claiming: true,
                    usable: true,
                );
                $entries[] = $entry;

                if ($entry['canonical_line_id'] === null) {
                    $invalid[] = [
                        'source' => $source,
                        'locator' => $entry['locator'],
                        'line_id' => $lineId,
                    ];
                }
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<array<string, string>>  $invalid
     * @return list<array<string, mixed>>
     */
    private function leaseEntries(
        string $source,
        array $document,
        string $portal,
        array &$invalid,
    ): array {
        $entries = [];

        foreach ($document as $key => $lease) {
            if (! is_array($lease) || ! is_scalar($lease['line_id'] ?? $key)) {
                $invalid[] = [
                    'source' => $source,
                    'locator' => 'lease:'.(string) $key,
                    'line_id' => 'invalid_lease',
                ];

                continue;
            }

            $lineId = (string) ($lease['line_id'] ?? $key);
            $entry = $this->entry(
                source: $source,
                locator: 'lease:'.(string) $key,
                recordId: null,
                portal: $portal,
                lineId: $lineId,
                identity: implode('|', [
                    trim((string) ($lease['owner_profile_key'] ?? '')),
                    rtrim(trim((string) ($lease['owner_callback_base_url'] ?? '')), '/'),
                    trim((string) ($lease['connector_code'] ?? '')),
                    trim((string) ($lease['connector_type'] ?? '')),
                ]),
                claiming: true,
                usable: true,
            );
            $entries[] = $entry;

            if ($entry['canonical_line_id'] === null) {
                $invalid[] = [
                    'source' => $source,
                    'locator' => $entry['locator'],
                    'line_id' => $lineId,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function analyzeEntries(array $entries): array
    {
        $migrations = [];
        $groups = [];

        foreach ($entries as $entry) {
            $canonical = $entry['canonical_line_id'] ?? null;

            if (! is_string($canonical)) {
                continue;
            }

            if ($entry['line_id'] !== $canonical) {
                $migrations[] = [
                    'source' => $entry['source'],
                    'locator' => $entry['locator'],
                    'record_id' => $entry['record_id'],
                    'record_field' => $entry['record_field'],
                    'portal' => $entry['portal'],
                    'from' => $entry['line_id'],
                    'to' => $canonical,
                    'identity' => $entry['identity'],
                    'claiming' => $entry['claiming'],
                    'usable' => $entry['usable'],
                ];
            }

            $groups[$entry['portal'].'#'.$canonical][] = $entry;
        }

        $collisions = [];

        foreach ($groups as $resource => $group) {
            $claiming = array_values(array_filter(
                $group,
                fn (array $entry): bool => ($entry['claiming'] ?? false) === true,
            ));
            $identities = array_values(array_unique(array_column($claiming, 'identity')));
            $sameSourceDuplicates = [];

            foreach ($claiming as $entry) {
                $sameSourceDuplicates[$entry['source']][] = $entry['locator'];
            }

            $sameSourceDuplicates = array_filter(
                $sameSourceDuplicates,
                fn (array $locators): bool => count($locators) > 1,
            );

            if (count($identities) <= 1 && $sameSourceDuplicates === []) {
                continue;
            }

            $collisions[] = [
                'resource' => $resource,
                'identities' => $identities,
                'entries' => array_map(
                    fn (array $entry): array => [
                        'source' => $entry['source'],
                        'locator' => $entry['locator'],
                        'line_id' => $entry['line_id'],
                        'identity' => $entry['identity'],
                    ],
                    $claiming,
                ),
            ];
        }

        return [$migrations, $collisions];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(
        string $source,
        string $locator,
        ?int $recordId,
        string $portal,
        string $lineId,
        string $identity,
        bool $claiming,
        bool $usable,
        ?string $recordField = null,
    ): array {
        return [
            'source' => $source,
            'locator' => $locator,
            'record_id' => $recordId,
            'record_field' => $recordField,
            'portal' => $this->normalizePortal($portal),
            'line_id' => $lineId,
            'canonical_line_id' => Bitrix24OpenLineRoute::canonicalLineId($lineId),
            'identity' => $identity,
            'claiming' => $claiming,
            'usable' => $usable,
        ];
    }

    /**
     * @param  list<array<string, string>>  $invalid
     * @return array<string, mixed>
     */
    private function readOptionalJson(string $path, string $source, array &$invalid): array
    {
        if (! is_file($path)) {
            return [];
        }

        try {
            $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $invalid[] = [
                'source' => $source,
                'locator' => $path,
                'line_id' => 'invalid_json',
            ];

            return [];
        }

        if (! is_array($decoded)) {
            $invalid[] = [
                'source' => $source,
                'locator' => $path,
                'line_id' => 'invalid_document',
            ];

            return [];
        }

        return $decoded;
    }

    private function migrateRegistryFiles(string $storageDirectory): void
    {
        foreach (['route_registry.json', 'route_registry.previous.json'] as $fileName) {
            $path = rtrim($storageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;

            if (! is_file($path)) {
                continue;
            }

            $document = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $owners = is_array($document['owners'] ?? null) ? $document['owners'] : [];

            foreach ($owners as $ownerKey => $owner) {
                if (! is_array($owner)) {
                    continue;
                }

                $routes = is_array($owner['routes'] ?? null) ? $owner['routes'] : [];
                $migratedRoutes = [];

                foreach ($routes as $route) {
                    if (! is_array($route)) {
                        continue;
                    }

                    $canonical = Bitrix24OpenLineRoute::canonicalLineId((string) ($route['line_id'] ?? ''));

                    if ($canonical === null) {
                        throw new Bitrix24OpenLineIdCompatibilityException(
                            'openlines_line_id_invalid',
                            'Registry содержит некорректный LINE_ID.',
                        );
                    }

                    $route['line_id'] = $canonical;
                    $routeKey = trim((string) ($route['connector_code'] ?? '')).':'.$canonical;

                    if (array_key_exists($routeKey, $migratedRoutes)) {
                        throw new Bitrix24OpenLineIdCompatibilityException(
                            'openlines_line_id_collision',
                            'Registry migration обнаружила collision ключей route.',
                        );
                    }

                    $migratedRoutes[$routeKey] = $route;
                }

                $owners[$ownerKey]['routes'] = $migratedRoutes;
            }

            $document['owners'] = $owners;
            $this->atomicWrite(
                $path,
                json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );
        }

        $leasePath = rtrim($storageDirectory, DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'route_registry_line_leases.json';

        if (! is_file($leasePath)) {
            return;
        }

        $leases = json_decode((string) file_get_contents($leasePath), true, flags: JSON_THROW_ON_ERROR);
        $migratedLeases = [];

        foreach ($leases as $lease) {
            if (! is_array($lease)) {
                continue;
            }

            $canonical = Bitrix24OpenLineRoute::canonicalLineId((string) ($lease['line_id'] ?? ''));

            if ($canonical === null || array_key_exists($canonical, $migratedLeases)) {
                throw new Bitrix24OpenLineIdCompatibilityException(
                    'openlines_line_id_collision',
                    'Lease migration обнаружила invalid/collision LINE_ID.',
                );
            }

            $lease['line_id'] = $canonical;
            $migratedLeases[$canonical] = $lease;
        }

        $this->atomicWrite(
            $leasePath,
            json_encode($migratedLeases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function sourceHashes(string $storageDirectory, array $entries): array
    {
        $hashes = [
            'database' => $this->entriesHash($entries, 'database'),
            'profile_database' => $this->entriesHash($entries, 'profile_database'),
        ];

        foreach (self::FILES as $source => $fileName) {
            $path = rtrim($storageDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$fileName;
            $hashes[$source] = is_file($path) ? hash_file('sha256', $path) : null;
        }

        return $hashes;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     */
    private function entriesHash(array $entries, string $source): string
    {
        $sourceEntries = array_values(array_filter(
            $entries,
            fn (array $entry): bool => ($entry['source'] ?? null) === $source,
        ));
        usort(
            $sourceEntries,
            fn (array $left, array $right): int => strcmp(
                (string) ($left['locator'] ?? ''),
                (string) ($right['locator'] ?? ''),
            ),
        );

        return hash(
            'sha256',
            json_encode(
                $sourceEntries,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $migration
     */
    private function migrateRouteDatabaseEntry(array $migration): void
    {
        $routeId = (int) ($migration['record_id'] ?? 0);
        $from = (string) ($migration['from'] ?? '');
        $to = (string) ($migration['to'] ?? '');
        $usable = (bool) ($migration['usable'] ?? false);
        $portal = (string) ($migration['portal'] ?? '');

        $updated = Bitrix24OpenLineRoute::query()
            ->whereKey($routeId)
            ->where('line_id', $from)
            ->update([
                'line_id' => $to,
                'line_owner_key' => $usable ? $portal.'#'.$to : null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_database_changed',
                'Маршрут изменился после compatibility preflight.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $migration
     */
    private function migrateProfileDatabaseEntry(array $migration): void
    {
        $profileId = (int) ($migration['record_id'] ?? 0);
        $field = (string) ($migration['record_field'] ?? '');
        $from = (string) ($migration['from'] ?? '');
        $to = (string) ($migration['to'] ?? '');

        if (! in_array($field, ['telegram_line_id', 'max_line_id'], true)) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_profile_field_invalid',
                'Compatibility migration получила неизвестное поле профиля Bitrix24.',
            );
        }

        $updated = Bitrix24Profile::query()
            ->whereKey($profileId)
            ->where($field, $from)
            ->update([
                $field => $to,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_profile_changed',
                'Старая настройка LINE_ID профиля изменилась после compatibility preflight.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $artifact
     * @return array<string, mixed>
     */
    private function writeArtifact(string $artifactPath, array $artifact): array
    {
        $artifactPath = trim($artifactPath);

        if ($artifactPath === '') {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_artifact_path_missing',
                'Для compatibility migration нужен явный путь artifact.',
            );
        }

        $this->atomicWrite(
            $artifactPath,
            json_encode($artifact, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $artifact + [
            'artifact_path' => $artifactPath,
            'artifact_sha256' => hash_file('sha256', $artifactPath),
        ];
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_write_unavailable',
                sprintf('Каталог %s недоступен для атомарной записи.', $directory),
            );
        }

        $tempPath = $path.'.'.getmypid().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tempPath, $contents, LOCK_EX) === false
            || ! @chmod($tempPath, 0600)
            || ! rename($tempPath, $path)
        ) {
            @unlink($tempPath);

            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_write_failed',
                sprintf('Не удалось атомарно записать %s.', $path),
            );
        }
    }

    private function normalizePortal(string $portal): string
    {
        return mb_strtolower(rtrim(trim($portal), '.'));
    }

    private function validatedStorageDirectory(string $storageDirectory): string
    {
        $storageDirectory = rtrim(trim($storageDirectory), DIRECTORY_SEPARATOR);
        $resolvedDirectory = $storageDirectory !== '' ? realpath($storageDirectory) : false;

        if ($resolvedDirectory === false || ! is_dir($resolvedDirectory)) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_storage_missing',
                'Каталог OpenLines registry для compatibility preflight недоступен.',
            );
        }

        return rtrim($resolvedDirectory, DIRECTORY_SEPARATOR);
    }

    private function validatedArtifactPath(string $storageDirectory, string $artifactPath): string
    {
        $artifactPath = trim($artifactPath);

        if ($artifactPath === '') {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_artifact_path_missing',
                'Для compatibility migration нужен явный путь artifact.',
            );
        }

        $artifactDirectory = realpath(dirname($artifactPath));

        if ($artifactDirectory === false) {
            return $artifactPath;
        }

        $candidatePath = $artifactDirectory.DIRECTORY_SEPARATOR.basename($artifactPath);
        $resolvedExistingPath = realpath($artifactPath);
        $protectedPaths = [];

        foreach ([
            ...array_values(self::FILES),
            'route_registry.lock',
            'route_registry_replay_cache.json',
            'route_registry_replay_cache.lock',
        ] as $fileName) {
            $protectedPath = $storageDirectory.DIRECTORY_SEPARATOR.$fileName;
            $protectedPaths[] = $protectedPath;

            foreach (glob($protectedPath.'.backup.*') ?: [] as $backupPath) {
                $resolvedBackupPath = realpath($backupPath);

                if ($resolvedBackupPath !== false) {
                    $protectedPaths[] = $resolvedBackupPath;
                }
            }
        }

        if (in_array($candidatePath, $protectedPaths, true)
            || ($resolvedExistingPath !== false
                && in_array($resolvedExistingPath, $protectedPaths, true))
        ) {
            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_artifact_path_conflict',
                'Путь artifact совпадает с runtime-файлом OpenLines registry.',
            );
        }

        return $artifactPath;
    }

    private function withRegistryLock(
        string $storageDirectory,
        int $lockMode,
        Closure $callback,
    ): mixed {
        $lock = fopen($storageDirectory.DIRECTORY_SEPARATOR.'route_registry.lock', 'c+');

        if (! is_resource($lock) || ! flock($lock, $lockMode)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new Bitrix24OpenLineIdCompatibilityException(
                'openlines_line_id_lock_unavailable',
                'Не удалось получить общий lock OpenLines registry.',
            );
        }

        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
