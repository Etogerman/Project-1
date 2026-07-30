<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Str;

class AutoSetupBitrix24OpenLineRouteAction
{
    public const SUPPORTED_PORTAL_DOMAIN = 'stagecrm.fvds.ru';

    private const GENERIC_APPLICATION_NAME = 'Abrikosoff Connector';

    /**
     * @var list<string>
     */
    public const REQUIRED_SCOPES = [
        'crm',
        'im',
        'imopenlines',
        'imconnector',
    ];

    public function __construct(
        private readonly Bitrix24ApiClient $apiClient,
        private readonly Bitrix24OpenLineRouteOperationLock $routeOperationLock,
        private readonly MarkBitrix24OpenLineRouteMisconfiguredAction $markRouteMisconfiguredAction,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
        private readonly Bitrix24OpenLinesRouteRegistrySnapshotLock $snapshotLock,
    ) {}

    public function refreshConnectorRegistration(Bitrix24Connection $connection, Bitrix24OpenLineRoute $route): Bitrix24OpenLineRoute
    {
        $profileId = (int) $route->bitrix24_profile_id;
        $channelId = (int) $route->channel_id;

        try {
            return $this->snapshotLock->run(
                fn (): Bitrix24OpenLineRoute => $this->routeOperationLock->run(
                    $profileId,
                    $channelId,
                    function () use (
                        $channelId,
                        $connection,
                        $profileId,
                    ): Bitrix24OpenLineRoute {
                        try {
                            return $this->refreshConnectorRegistrationUnderLock(
                                $connection,
                                $profileId,
                                $channelId,
                            );
                        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
                            $message = $this->ownershipErrorMessage($exception);

                            throw new Bitrix24OpenLineAutoSetupException(
                                $message,
                                previous: $exception,
                            );
                        }
                    },
                ),
            );
        } catch (LockTimeoutException $exception) {
            $message = $exception->getMessage() === Bitrix24OpenLinesRouteRegistrySnapshotLock::BUSY_MESSAGE
                ? Bitrix24OpenLinesRouteRegistrySnapshotLock::BUSY_MESSAGE
                : Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE;

            throw new Bitrix24OpenLineAutoSetupException(
                $message,
                previous: $exception,
            );
        } catch (Bitrix24OpenLineMutationAuthorityException $exception) {
            throw new Bitrix24OpenLineAutoSetupException(
                'Маршрут изменился во время операции. Обновите карточку и повторите действие.',
                previous: $exception,
            );
        }
    }

    private function refreshConnectorRegistrationUnderLock(
        Bitrix24Connection $connection,
        int $profileId,
        int $channelId,
    ): Bitrix24OpenLineRoute {
        $connection->loadMissing('profile');
        $route = Bitrix24OpenLineRoute::query()
            ->select('bitrix24_open_line_routes.*')
            ->selectRaw('bitrix24_open_line_routes.xmin::text as state_version')
            ->with(['bitrix24Profile', 'callbackOwner', 'channel'])
            ->where('bitrix24_profile_id', $profileId)
            ->where('channel_id', $channelId)
            ->first();

        if (! $route instanceof Bitrix24OpenLineRoute) {
            throw new Bitrix24OpenLineAutoSetupException('Маршрут ОЛ больше не существует.');
        }

        $profile = $route->bitrix24Profile;
        $owner = $route->callbackOwner;
        $channel = $route->channel;

        if (! $profile instanceof Bitrix24Profile
            || ! $owner instanceof Bitrix24CallbackOwner
            || ! $channel instanceof Channel
        ) {
            throw new Bitrix24OpenLineAutoSetupException(
                'Маршрут ОЛ не связан с профилем Bitrix24, callback-владельцем или каналом.',
            );
        }

        if ((int) $connection->profile_id !== (int) $profile->id) {
            throw new Bitrix24OpenLineAutoSetupException('Bitrix24-подключение относится к другому профилю.');
        }

        $this->assertRefreshContextSupported($connection, $profile, $channel, $route);
        $routeIdentity = $this->canonicalRefreshRouteIdentity($channel, $route);

        try {
            return $this->routeOperationLock->runForOwnedLine(
                $profile,
                $owner,
                $routeIdentity['connector_code'],
                $routeIdentity['connector_type'],
                $routeIdentity['line_id'],
                fn (
                    Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
                    Bitrix24OpenLineMutationAuthority $authority,
                ): Bitrix24OpenLineRoute => $this->refreshConnectorRegistrationForLine(
                    $connection,
                    $profile,
                    $channel,
                    $route,
                    $routeIdentity['connector_code'],
                    $routeIdentity['line_id'],
                    $leaseDeadline,
                    $authority,
                ),
                scope: Bitrix24OpenLineMutationAuthority::SCOPE_CONNECTOR_REGISTRATION,
                route: $route,
                operationType: 'refresh_connector_registration',
            );
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            if ($exception->errorCode === 'route_registry_line_owner_conflict') {
                $this->markRouteMisconfiguredAction->handleExpected(
                    $route,
                    $this->ownershipErrorMessage($exception),
                );
            }

            throw $exception;
        }
    }

    private function refreshConnectorRegistrationForLine(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        Bitrix24OpenLineRoute $route,
        string $connectorCode,
        string $lineId,
        Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
        Bitrix24OpenLineMutationAuthority $authority,
    ): Bitrix24OpenLineRoute {
        $refreshedRoute = null;

        try {
            $this->assertRouteConfigurationValidForRefresh($profile, $channel, $route, $authority);
            $connection = $this->refreshApplicationNameForConnectorRegistration(
                $connection,
                $leaseDeadline,
                $authority,
            );
            $this->routeOperationLock->assertLeaseAllowsRemoteOperation($leaseDeadline);
            $this->registerConnector(
                $connection,
                $profile,
                $channel,
                $connectorCode,
                $leaseDeadline,
            );
            $this->routeOperationLock->assertLeaseAllowsRemoteOperation($leaseDeadline);
            $this->setConnectorData(
                $connection,
                $profile,
                $channel,
                $connectorCode,
                $lineId,
                $leaseDeadline,
            );

            $this->routeOperationLock->assertLeaseAllowsRemoteOperation($leaseDeadline);
            $this->syncOpenLineCrmSettings(
                $connection,
                $lineId,
                (string) $route->source_id,
                $leaseDeadline,
            );
            $refreshedRoute = $this->completeRefreshStateTransition($authority);
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->markRouteError($route, $exception->getMessage(), $authority);

            throw $exception;
        } catch (Bitrix24AuthRefreshException $exception) {
            $detail = $this->sanitizeErrorMessage($exception->getMessage());
            $message = $detail === null
                ? 'Не удалось обновить авторизацию Bitrix24.'
                : 'Не удалось обновить авторизацию Bitrix24: '.$detail;

            throw new Bitrix24OpenLineAutoSetupException($message, previous: $exception);
        } catch (Bitrix24ApiException $exception) {
            $message = $exception->getMessage() !== ''
                ? $exception->getMessage()
                : 'Не удалось обновить регистрацию соединителя Bitrix24.';

            $this->markRouteError($route, $message, $authority);

            throw new Bitrix24OpenLineAutoSetupException($message, previous: $exception);
        }

        if (! $refreshedRoute instanceof Bitrix24OpenLineRoute) {
            throw new Bitrix24OpenLineAutoSetupException(
                'Маршрут ОЛ изменился во время обновления. Обновление карточки не завершено; проверьте актуальное состояние маршрута.',
            );
        }

        return $refreshedRoute;
    }

    private function completeRefreshStateTransition(
        Bitrix24OpenLineMutationAuthority $authority,
    ): ?Bitrix24OpenLineRoute {
        return $this->mutationFence->runMutation(
            $authority,
            function (?Bitrix24OpenLineRoute $lockedRoute): ?Bitrix24OpenLineRoute {
                if (! $lockedRoute instanceof Bitrix24OpenLineRoute) {
                    throw new Bitrix24OpenLineAutoSetupException('Маршрут ОЛ больше не существует.');
                }

                $lockedRoute->forceFill([
                    'last_error_message' => null,
                    'last_error_at' => null,
                    'updated_at' => now(),
                ])->save();

                return $lockedRoute->refresh();
            },
        );
    }

    private function isAutoSetupSupportedChannel(Channel $channel): bool
    {
        return in_array(Bitrix24OpenLineRoute::channelTypeForChannel($channel), [
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
        ], true);
    }

    private function assertRequiredScopes(Bitrix24Connection $connection): void
    {
        $actualScopes = collect($connection->scope ?? [])
            ->map(fn (mixed $scope): string => mb_strtolower(trim((string) $scope)))
            ->filter()
            ->values()
            ->all();

        if (in_array('app', $actualScopes, true)) {
            return;
        }

        $missing = array_values(array_diff(self::REQUIRED_SCOPES, $actualScopes));

        if ($missing === []) {
            return;
        }

        throw new Bitrix24OpenLineAutoSetupException(
            'Bitrix24 отклонил настройку. Проверьте права приложения: CRM, чат, открытые линии и коннекторы.',
        );
    }

    private function assertRefreshContextSupported(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        Bitrix24OpenLineRoute $route,
    ): void {
        if ($connection->status !== Bitrix24Connection::STATUS_ACTIVE) {
            throw new Bitrix24OpenLineAutoSetupException('Bitrix24-подключение не активно.');
        }

        if ($profile->portal_domain !== self::SUPPORTED_PORTAL_DOMAIN) {
            throw new Bitrix24OpenLineAutoSetupException('Обновление регистрации ОЛ доступно только для stagecrm.fvds.ru.');
        }

        if (! $this->isAutoSetupSupportedChannel($channel)) {
            throw new Bitrix24OpenLineAutoSetupException('Обновление регистрации ОЛ сейчас доступно только для Telegram bot и MAX bot каналов.');
        }

        if (! in_array($route->status, [
            Bitrix24OpenLineRoute::STATUS_ACTIVE,
            Bitrix24OpenLineRoute::STATUS_LEGACY,
            Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ], true)) {
            throw new Bitrix24OpenLineAutoSetupException('Маршрут ОЛ не активен и не находится в состоянии ремонта.');
        }

        $this->assertRequiredScopes($connection);
    }

    /**
     * @return array{connector_code:string,connector_type:string,line_id:string}
     */
    private function canonicalRefreshRouteIdentity(
        Channel $channel,
        Bitrix24OpenLineRoute $route,
    ): array {
        $connectorCode = trim((string) $route->connector_code);
        $storedLineId = (string) $route->line_id;
        $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType(
            Bitrix24OpenLineRoute::channelTypeForChannel($channel),
        );

        if (! Bitrix24OpenLineRoute::isValidConnectorCode($connectorCode)) {
            throw new Bitrix24OpenLineAutoSetupException(
                'Код соединителя должен состоять из 1–64 латинских букв, цифр или символов ".", "_" и "-".',
            );
        }

        if (! Bitrix24OpenLineRoute::isValidLineId($storedLineId)) {
            throw new Bitrix24OpenLineAutoSetupException(
                'LINE_ID маршрута неканоничен. Сначала выполните compatibility preflight/migration.',
            );
        }

        if (! is_string($connectorType)) {
            throw new Bitrix24OpenLineAutoSetupException(
                'Обновление регистрации ОЛ сейчас доступно только для Telegram bot и MAX bot каналов.',
            );
        }

        return [
            'connector_code' => $connectorCode,
            'connector_type' => $connectorType,
            'line_id' => $storedLineId,
        ];
    }

    private function assertRouteConfigurationValidForRefresh(
        Bitrix24Profile $profile,
        Channel $channel,
        Bitrix24OpenLineRoute $route,
        Bitrix24OpenLineMutationAuthority $authority,
    ): void {
        foreach ([
            'connector_code' => 'В маршруте ОЛ не заполнен код соединителя.',
            'line_id' => 'В маршруте ОЛ не заполнена открытая линия.',
            'source_id' => 'В маршруте ОЛ не заполнен CRM source.',
        ] as $field => $message) {
            if (! filled($route->{$field})) {
                throw new Bitrix24OpenLineAutoSetupException($message);
            }
        }

        $this->mutationFence->runMutation(
            $authority,
            fn () => $this->assertLineIsNotUsedByAnotherRoute(
                $profile,
                $channel,
                (string) $route->line_id,
            ),
        );
    }

    private function syncOpenLineCrmSettings(
        Bitrix24Connection $connection,
        string $lineId,
        string $sourceId,
        Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
    ): void {
        $response = $this->apiClient->call('imopenlines.config.update', [
            'CONFIG_ID' => $lineId,
            'PARAMS' => [
                'CRM' => 'Y',
                'CRM_CREATE' => 'deal',
                'CRM_SOURCE' => $sourceId,
            ],
        ], $connection, beforeRestAttempt: $this->leasePreflight($leaseDeadline));

        $this->assertSuccessfulBooleanResult($response, 'Не удалось синхронизировать CRM-настройки открытой линии Bitrix24.');
    }

    private function registerConnector(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
    ): void {
        $connectorName = $this->buildConnectorName($connection, $channel);

        $response = $this->apiClient->call('imconnector.register', [
            'ID' => $connectorCode,
            'NAME' => $connectorName,
            'ICON' => $this->connectorIcon($channel),
            'ICON_DISABLED' => $this->connectorIcon($channel, disabled: true),
            'PLACEMENT_HANDLER' => $this->settingsUrl($profile, $connection),
            'DEL_EXTERNAL_MESSAGES' => true,
            'EDIT_INTERNAL_MESSAGES' => true,
            'DEL_INTERNAL_MESSAGES' => true,
            'NEWSLETTER' => true,
            'NEED_SYSTEM_MESSAGES' => true,
            'NEED_SIGNATURE' => true,
            'CHAT_GROUP' => false,
            'COMMENT' => 'Настройки канала '.$connectorName,
        ], $connection, beforeRestAttempt: $this->leasePreflight($leaseDeadline));

        $this->assertSuccessfulNestedResult($response, 'Не удалось зарегистрировать соединитель Bitrix24.');
    }

    private function setConnectorData(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
    ): void {
        $channelUrl = $this->settingsUrl($profile, $connection);
        $connectorName = $this->buildConnectorName($connection, $channel);

        $response = $this->apiClient->call('imconnector.connector.data.set', [
            'CONNECTOR' => $connectorCode,
            'LINE' => $lineId,
            'DATA' => [
                'ID' => $this->connectorDataExternalId($channel, $connectorCode, $lineId),
                'URL' => $channelUrl,
                'URL_IM' => $channelUrl,
                'NAME' => $connectorName,
            ],
        ], $connection, beforeRestAttempt: $this->leasePreflight($leaseDeadline));

        $this->assertSuccessfulBooleanResult($response, 'Не удалось сохранить настройки соединителя Bitrix24.');
    }

    private function assertLineIsNotUsedByAnotherRoute(Bitrix24Profile $profile, Channel $channel, string $lineId): void
    {
        $hasConflict = Bitrix24OpenLineRoute::query()
            ->where('portal_domain', $profile->portal_domain)
            ->where('line_id', $lineId)
            ->where('channel_id', '!=', $channel->id)
            ->whereIn('status', [
                Bitrix24OpenLineRoute::STATUS_ACTIVE,
                Bitrix24OpenLineRoute::STATUS_LEGACY,
                Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            ])
            ->exists();

        if ($hasConflict) {
            throw new Bitrix24OpenLineAutoSetupException('Открытая линия уже занята другим маршрутом.');
        }
    }

    private function leasePreflight(Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline): \Closure
    {
        return fn () => $this->routeOperationLock->assertLeaseAllowsRemoteOperation($leaseDeadline);
    }

    private function assertSuccessfulResponse(Bitrix24RestResponseData $response, string $message): void
    {
        if ($response->successful) {
            return;
        }

        throw new Bitrix24OpenLineAutoSetupException($this->buildApiErrorMessage($message, $response));
    }

    private function assertSuccessfulBooleanResult(Bitrix24RestResponseData $response, string $message): void
    {
        $this->assertSuccessfulResponse($response, $message);

        if ($response->result === true || $response->result === 1 || $response->result === '1') {
            return;
        }

        throw new Bitrix24OpenLineAutoSetupException($message);
    }

    private function assertSuccessfulNestedResult(Bitrix24RestResponseData $response, string $message): void
    {
        $this->assertSuccessfulResponse($response, $message);

        $result = $response->result;

        if (is_array($result) && ($result['result'] ?? null) === true) {
            return;
        }

        $nestedError = is_array($result)
            ? $this->sanitizeErrorMessage($result['error_description'] ?? $result['error'] ?? null)
            : null;

        throw new Bitrix24OpenLineAutoSetupException($nestedError ?: $message);
    }

    private function buildApiErrorMessage(string $message, Bitrix24RestResponseData $response): string
    {
        $errorMessage = $this->sanitizeErrorMessage($response->errorMessage ?? $response->errorCode);

        return $errorMessage === null ? $message : $message.' '.$errorMessage;
    }

    private function buildConnectorName(Bitrix24Connection $connection, Channel $channel): string
    {
        $platformName = match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => 'MAX',
            default => 'Telegram',
        };

        return Str::limit($this->connectorNamePrefix($connection).' '.$platformName, 120, '');
    }

    private function connectorNamePrefix(Bitrix24Connection $connection): string
    {
        $connectionName = $this->displayName($connection->application_name);

        if ($connectionName !== null && ! $this->isGenericApplicationName($connectionName)) {
            return $connectionName;
        }

        $configuredName = $this->configuredApplicationDisplayName();

        return $configuredName ?? 'ABC';
    }

    private function connectorDataExternalId(Channel $channel, string $connectorCode, string $lineId): string
    {
        $value = sprintf('channel:%d:connector:%s:line:%s', $channel->id, $connectorCode, $lineId);
        $normalized = preg_replace('/[^A-Za-z0-9_.:-]+/', '_', $value);

        return Str::limit($normalized ?? $value, 255, '');
    }

    private function refreshApplicationNameForConnectorRegistration(
        Bitrix24Connection $connection,
        Bitrix24OpenLineRouteLeaseDeadline $leaseDeadline,
        Bitrix24OpenLineMutationAuthority $authority,
    ): Bitrix24Connection {
        $currentName = $this->displayName($connection->application_name);

        if ($currentName !== null && ! $this->isGenericApplicationName($currentName)) {
            return $connection;
        }

        $applicationName = $this->configuredApplicationDisplayName();

        if ($applicationName === null || $applicationName === $currentName) {
            return $connection;
        }

        $this->routeOperationLock->assertLeaseAllowsDatabaseTransition($leaseDeadline);

        return $this->mutationFence->runMutation(
            $authority,
            function () use ($connection, $applicationName, $leaseDeadline): Bitrix24Connection {
                $lockedConnection = Bitrix24Connection::query()
                    ->whereKey($connection->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->routeOperationLock->assertLeaseAllowsDatabaseTransition($leaseDeadline);
                $lockedConnection->forceFill([
                    'application_name' => $applicationName,
                ])->save();

                return $lockedConnection->refresh();
            },
        );
    }

    private function markRouteError(
        Bitrix24OpenLineRoute $route,
        string $message,
        Bitrix24OpenLineMutationAuthority $authority,
    ): void {
        $this->markRouteMisconfiguredAction->handle(
            (int) $route->getKey(),
            $this->sanitizeErrorMessage($message),
            $authority,
        );
    }

    private function ownershipErrorMessage(Bitrix24OpenLinesRouteRegistryException $exception): string
    {
        return match ($exception->errorCode) {
            'route_registry_line_busy' => 'Открытая линия сейчас изменяется в другом контуре. Повторите попытку после завершения операции.',
            'route_registry_connector_busy' => 'Соединитель сейчас изменяется в другом контуре. Повторите попытку после завершения операции.',
            'route_registry_line_owner_missing' => 'Для этой открытой линии ещё не опубликован владелец в общем OpenLines registry. Сначала выполните разрешённую публикацию ownership.',
            'route_registry_line_owner_conflict' => 'Открытая линия закреплена в общем OpenLines registry за другим контуром.',
            'route_registry_line_lease_expiring' => 'Срок общей аренды открытой линии недостаточен для безопасного завершения операции. Повторите попытку.',
            default => 'Не удалось подтвердить единое владение открытой линией в общем OpenLines registry.',
        };
    }

    private function configuredApplicationDisplayName(): ?string
    {
        $configuredName = $this->displayName(config('bitrix24.application.name'));

        if ($configuredName === null || $this->isGenericApplicationName($configuredName)) {
            return null;
        }

        return $configuredName;
    }

    private function isGenericApplicationName(string $name): bool
    {
        return mb_strtolower(trim($name)) === mb_strtolower(self::GENERIC_APPLICATION_NAME);
    }

    private function displayName(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $name = trim((string) $value);

        return $name === '' ? null : $name;
    }

    /**
     * @return array{DATA_IMAGE: string, COLOR: string, SIZE: string, POSITION: string}
     */
    private function connectorIcon(Channel $channel, bool $disabled = false): array
    {
        return [
            'DATA_IMAGE' => $this->connectorIconDataUri($channel),
            'COLOR' => $disabled ? '#99ADB3' : $this->connectorIconColor($channel),
            'SIZE' => '90%',
            'POSITION' => 'center',
        ];
    }

    private function connectorIconDataUri(Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => $this->maxIconDataUri(),
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT => $this->telegramAccountIconDataUri(),
            default => $this->telegramIconDataUri(),
        };
    }

    private function connectorIconColor(Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => '#7C3AED',
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT => '#0F766E',
            default => '#2AABEE',
        };
    }

    private function settingsUrl(Bitrix24Profile $profile, Bitrix24Connection $connection): string
    {
        return rtrim($profile->callback_base_url, '/').'/admin/bitrix24-connections/'.$connection->id;
    }

    private function telegramIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><path fill="white" d="M27.7 5.3 4.9 14.1c-1.6.6-1.6 1.5-.3 1.9l5.9 1.8 2.2 6.8c.3.8.1 1.1 1 .4l3.1-3 6.4 4.7c1.2.7 2 .3 2.3-1.1L29.6 7c.4-1.4-.5-2.1-1.9-1.7ZM11.4 17.4 24.7 9c.7-.4 1.3-.2.8.3L14.1 19.6l-.4 4.1-2.3-6.3Z"/></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    private function telegramAccountIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><circle cx="16" cy="10.6" r="5.2" fill="white"/><path fill="white" d="M6.4 27c1.4-6.2 5.1-9.4 9.6-9.4s8.2 3.2 9.6 9.4H6.4Z"/></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    private function maxIconDataUri(): string
    {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32"><text x="16" y="20.5" fill="white" font-family="Arial, Helvetica, sans-serif" font-size="11" font-weight="700" text-anchor="middle">MAX</text></svg>';

        return 'data:image/svg+xml,'.rawurlencode($svg);
    }

    private function sanitizeErrorMessage(mixed $message): ?string
    {
        if (! is_scalar($message)) {
            return null;
        }

        $normalized = trim((string) $message);

        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/(access_token|refresh_token|auth|client_secret|token)=\S+/i', '$1=***', $normalized) ?? $normalized;

        return Str::limit($normalized, 500, '');
    }
}
