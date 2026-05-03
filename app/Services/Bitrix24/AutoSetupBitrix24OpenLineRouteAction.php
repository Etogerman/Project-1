<?php

namespace App\Services\Bitrix24;

use App\Data\Bitrix24\Bitrix24RestResponseData;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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
    ) {}

    public function handle(Bitrix24Connection $connection, Channel $channel, ?User $user = null): Bitrix24OpenLineRoute
    {
        $connection->loadMissing('profile');
        $profile = $connection->profile;

        if (! $profile instanceof Bitrix24Profile) {
            throw new Bitrix24OpenLineAutoSetupException('У подключения Bitrix24 нет профиля.');
        }

        $this->assertCanAutoSetup($connection, $profile, $channel);

        $lock = Cache::lock($this->lockKey($profile, $channel), 30);

        if (! $lock->get()) {
            throw new Bitrix24OpenLineAutoSetupException('Настройка уже выполняется. Подождите несколько секунд и обновите страницу.');
        }

        try {
            return $this->setupRoute($connection, $profile, $channel, $user);
        } finally {
            $lock->release();
        }
    }

    private function setupRoute(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        ?User $user,
    ): Bitrix24OpenLineRoute {
        $connectorCode = $this->requiredConnectorCode($profile, $channel);
        $sourceId = $this->requiredSourceId($profile, $channel);
        $route = $this->findRoute($profile, $channel);
        $lineId = $this->normalizedRouteLineId($route);

        if ($lineId !== null) {
            $this->assertLineIsNotUsedByAnotherRoute($profile, $channel, $lineId);
        } else {
            $lineId = $this->createOpenLine($connection, $profile, $channel, $sourceId);
            $route = $this->saveRoute(
                route: $route,
                profile: $profile,
                channel: $channel,
                connectorCode: $connectorCode,
                lineId: $lineId,
                sourceId: $sourceId,
                status: Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                errorMessage: 'Открытая линия создана, настройка соединителя ещё не завершена.',
                user: $user,
            );
        }

        try {
            $connection = $this->refreshApplicationNameForConnectorRegistration($connection);
            $this->registerConnector($connection, $profile, $channel, $connectorCode, $sourceId);
            $this->setConnectorData($connection, $profile, $channel, $connectorCode, $lineId, $sourceId);
            $this->activateConnector($connection, $connectorCode, $lineId);
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->saveRoute(
                route: $route,
                profile: $profile,
                channel: $channel,
                connectorCode: $connectorCode,
                lineId: $lineId,
                sourceId: $sourceId,
                status: Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
                errorMessage: $exception->getMessage(),
                user: $user,
            );

            throw $exception;
        }

        return $this->saveRoute(
            route: $route,
            profile: $profile,
            channel: $channel,
            connectorCode: $connectorCode,
            lineId: $lineId,
            sourceId: $sourceId,
            status: Bitrix24OpenLineRoute::STATUS_ACTIVE,
            errorMessage: null,
            user: $user,
        );
    }

    public function refreshConnectorRegistration(Bitrix24Connection $connection, Bitrix24OpenLineRoute $route): Bitrix24OpenLineRoute
    {
        $connection->loadMissing('profile');
        $route->loadMissing(['bitrix24Profile', 'channel']);

        $profile = $route->bitrix24Profile;
        $channel = $route->channel;

        if (! $profile instanceof Bitrix24Profile || ! $channel instanceof Channel) {
            throw new Bitrix24OpenLineAutoSetupException('Маршрут ОЛ не связан с профилем Bitrix24 или каналом.');
        }

        if ((int) $connection->profile_id !== (int) $profile->id) {
            throw new Bitrix24OpenLineAutoSetupException('Bitrix24-подключение относится к другому профилю.');
        }

        $this->assertCanRefreshConnectorRegistration($connection, $profile, $channel, $route);

        try {
            $connection = $this->refreshApplicationNameForConnectorRegistration($connection);
            $this->registerConnector($connection, $profile, $channel, (string) $route->connector_code, (string) $route->source_id);
            $this->setConnectorData($connection, $profile, $channel, (string) $route->connector_code, (string) $route->line_id, (string) $route->source_id);
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->markRouteError($route, $exception->getMessage());

            throw $exception;
        }

        $route->forceFill([
            'last_error_message' => null,
            'last_error_at' => null,
        ])->save();

        return $route->refresh();
    }

    private function assertCanAutoSetup(Bitrix24Connection $connection, Bitrix24Profile $profile, Channel $channel): void
    {
        if ($connection->status !== Bitrix24Connection::STATUS_ACTIVE) {
            throw new Bitrix24OpenLineAutoSetupException('Bitrix24-подключение не активно.');
        }

        if ($profile->portal_domain !== self::SUPPORTED_PORTAL_DOMAIN) {
            throw new Bitrix24OpenLineAutoSetupException('Автонастройка ОЛ в первом срезе доступна только для stagecrm.fvds.ru.');
        }

        if (! $this->isAutoSetupSupportedChannel($channel)) {
            throw new Bitrix24OpenLineAutoSetupException('Автонастройка ОЛ сейчас доступна только для Telegram bot и MAX bot каналов.');
        }

        if (! $channel->is_active) {
            throw new Bitrix24OpenLineAutoSetupException('Канал выключен в нашей админке.');
        }

        if (! $channel->hasBotTokenConfigured()) {
            throw new Bitrix24OpenLineAutoSetupException('У канала нет токена.');
        }

        $this->requiredConnectorCode($profile, $channel);
        $this->requiredSourceId($profile, $channel);
        $this->assertRequiredScopes($connection);
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

    private function assertCanRefreshConnectorRegistration(
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

        if (! $route->isUsable()) {
            throw new Bitrix24OpenLineAutoSetupException('Маршрут ОЛ не активен или не содержит открытую линию.');
        }

        foreach ([
            'connector_code' => 'В маршруте ОЛ не заполнен код соединителя.',
            'line_id' => 'В маршруте ОЛ не заполнена открытая линия.',
            'source_id' => 'В маршруте ОЛ не заполнен CRM source.',
        ] as $field => $message) {
            if (! filled($route->{$field})) {
                throw new Bitrix24OpenLineAutoSetupException($message);
            }
        }

        $this->assertRequiredScopes($connection);
    }

    private function createOpenLine(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        string $sourceId,
    ): string {
        $response = $this->apiClient->call('imopenlines.config.add', [
            'PARAMS' => [
                'LINE_NAME' => $this->buildLineName($profile, $channel),
                'ACTIVE' => 'Y',
                'CRM' => 'Y',
                'CRM_CREATE' => 'deal',
                'CRM_SOURCE' => $sourceId,
            ],
        ], $connection);

        $this->assertSuccessfulResponse($response, 'Не удалось создать открытую линию в Bitrix24.');

        if (! is_scalar($response->result) || trim((string) $response->result) === '') {
            throw new Bitrix24OpenLineAutoSetupException('Bitrix24 не вернул ID созданной открытой линии.');
        }

        return trim((string) $response->result);
    }

    private function registerConnector(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $sourceId,
    ): void {
        $connectorName = $this->buildConnectorName($connection, $sourceId, $channel);

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
        ], $connection);

        $this->assertSuccessfulNestedResult($response, 'Не удалось зарегистрировать соединитель Bitrix24.');
    }

    private function setConnectorData(
        Bitrix24Connection $connection,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        string $sourceId,
    ): void {
        $channelUrl = $this->settingsUrl($profile, $connection);
        $connectorName = $this->buildConnectorName($connection, $sourceId, $channel);

        $response = $this->apiClient->call('imconnector.connector.data.set', [
            'CONNECTOR' => $connectorCode,
            'LINE' => $lineId,
            'DATA' => [
                'ID' => 'channel:'.$channel->id,
                'URL' => $channelUrl,
                'URL_IM' => $channelUrl,
                'NAME' => $connectorName,
            ],
        ], $connection);

        $this->assertSuccessfulBooleanResult($response, 'Не удалось сохранить настройки соединителя Bitrix24.');
    }

    private function activateConnector(Bitrix24Connection $connection, string $connectorCode, string $lineId): void
    {
        $response = $this->apiClient->call('imconnector.activate', [
            'CONNECTOR' => $connectorCode,
            'LINE' => $lineId,
            'ACTIVE' => '1',
        ], $connection);

        $this->assertSuccessfulBooleanResult($response, 'Не удалось активировать соединитель Bitrix24.');
    }

    private function saveRoute(
        ?Bitrix24OpenLineRoute $route,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        string $sourceId,
        string $status,
        ?string $errorMessage,
        ?User $user,
    ): Bitrix24OpenLineRoute {
        $route ??= new Bitrix24OpenLineRoute([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'created_by_user_id' => $user?->id,
        ]);

        $route->fill([
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $connectorCode,
            'line_id' => $lineId,
            'source_id' => $sourceId,
            'status' => $status,
            'last_error_message' => $this->sanitizeErrorMessage($errorMessage),
            'last_error_at' => $errorMessage === null ? null : now(),
            'updated_by_user_id' => $user?->id,
        ]);

        $route->save();

        $this->syncProfileRouteFields($profile, $channel, $connectorCode, $lineId, $sourceId, $status);

        return $route;
    }

    private function syncProfileRouteFields(
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        string $sourceId,
        string $status,
    ): void {
        if ($status !== Bitrix24OpenLineRoute::STATUS_ACTIVE) {
            return;
        }

        $fields = match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT => [
                'telegram_connector_code' => $connectorCode,
                'telegram_line_id' => $lineId,
                'telegram_source_id' => $sourceId,
            ],
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => [
                'max_connector_code' => $connectorCode,
                'max_line_id' => $lineId,
                'max_source_id' => $sourceId,
            ],
            default => [],
        };

        if ($fields === []) {
            return;
        }

        $profile->fill($fields);

        if ($profile->isDirty()) {
            $profile->save();
        }
    }

    private function findRoute(Bitrix24Profile $profile, Channel $channel): ?Bitrix24OpenLineRoute
    {
        return Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->first();
    }

    private function normalizedRouteLineId(?Bitrix24OpenLineRoute $route): ?string
    {
        if (! $route instanceof Bitrix24OpenLineRoute || ! filled($route->line_id)) {
            return null;
        }

        return trim((string) $route->line_id);
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

    private function requiredProfileValue(mixed $value, string $message): string
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            throw new Bitrix24OpenLineAutoSetupException($message);
        }

        return trim((string) $value);
    }

    private function buildLineName(Bitrix24Profile $profile, Channel $channel): string
    {
        $prefix = sprintf('Abrikosoff / %s / #%d ', $profile->profile_key, $channel->id);
        $maxLength = 120;
        $suffix = Str::limit($channel->name, max(10, $maxLength - mb_strlen($prefix)), '');

        return $prefix.$suffix;
    }

    private function buildConnectorName(Bitrix24Connection $connection, string $sourceId, ?Channel $channel = null): string
    {
        $sourceName = $this->sourceShortName($sourceId);
        $parts = array_values(array_filter([
            $this->applicationDisplayName($connection),
            $sourceName,
            $this->connectorChannelLabel($channel),
        ]));

        return Str::limit(implode(' ', $parts), 120, '');
    }

    private function refreshApplicationNameForConnectorRegistration(Bitrix24Connection $connection): Bitrix24Connection
    {
        $currentName = $this->displayName($connection->application_name);

        if ($currentName !== null && ! $this->isGenericApplicationName($currentName)) {
            return $connection;
        }

        $applicationName = $this->configuredApplicationDisplayName();

        if ($applicationName === null || $applicationName === $currentName) {
            return $connection;
        }

        $connection->forceFill([
            'application_name' => $applicationName,
        ])->save();

        return $connection->refresh();
    }

    private function markRouteError(Bitrix24OpenLineRoute $route, string $message): void
    {
        $route->forceFill([
            'last_error_message' => $this->sanitizeErrorMessage($message),
            'last_error_at' => now(),
        ])->save();
    }

    private function applicationDisplayName(Bitrix24Connection $connection): string
    {
        $connectionName = $this->displayName($connection->application_name);

        if ($connectionName !== null && ! $this->isGenericApplicationName($connectionName)) {
            return $connectionName;
        }

        $configuredName = $this->configuredApplicationDisplayName();

        if ($configuredName !== null) {
            return $configuredName;
        }

        return $connectionName ?? 'Abrikosoff';
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

    private function sourceShortName(string $sourceId): ?string
    {
        if (preg_match('/^([a-z0-9]+)[_-](?:telegram|max)(?:[_-]|$)/i', trim($sourceId), $matches) !== 1) {
            return null;
        }

        return mb_strtoupper($matches[1]);
    }

    private function requiredConnectorCode(Bitrix24Profile $profile, Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT => $this->requiredProfileValue(
                $profile->telegram_connector_code,
                'В профиле Bitrix24 не заполнен Telegram connector_code.',
            ),
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => $this->requiredProfileValue(
                $profile->max_connector_code,
                'В профиле Bitrix24 не заполнен MAX connector_code.',
            ),
            default => throw new Bitrix24OpenLineAutoSetupException('Автонастройка ОЛ сейчас доступна только для Telegram bot и MAX bot каналов.'),
        };
    }

    private function requiredSourceId(Bitrix24Profile $profile, Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT => $this->requiredProfileValue(
                $profile->telegram_source_id,
                'В профиле Bitrix24 не заполнен Telegram source_id.',
            ),
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => $this->requiredProfileValue(
                $profile->max_source_id,
                'В профиле Bitrix24 не заполнен MAX source_id.',
            ),
            default => throw new Bitrix24OpenLineAutoSetupException('Автонастройка ОЛ сейчас доступна только для Telegram bot и MAX bot каналов.'),
        };
    }

    private function connectorChannelLabel(?Channel $channel): string
    {
        if (! $channel instanceof Channel) {
            return 'Telegram bot';
        }

        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => 'MAX bot',
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT => 'Telegram account',
            default => 'Telegram bot',
        };
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

    private function lockKey(Bitrix24Profile $profile, Channel $channel): string
    {
        return sprintf('bitrix24-openline-autosetup:%d:%d', $profile->id, $channel->id);
    }
}
