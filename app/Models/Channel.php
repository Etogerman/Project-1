<?php

namespace App\Models;

use App\Services\TelegramAccount\ResolveTelegramAccountGatewayDiagnosticsAction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class Channel extends Model
{
    use HasFactory;

    public const PLATFORM_TELEGRAM = 'telegram';

    public const PLATFORM_MAX = 'max';

    public const CONNECTION_TYPE_BOT = 'bot';

    public const CONNECTION_TYPE_ACCOUNT = 'account';

    public const CREDENTIAL_TOKEN = 'token';

    public const CREDENTIAL_WEBHOOK_SECRET = 'webhook_secret';

    public const AUTO_REPLY_MODE_LEGACY_DEFAULT = 'legacy_default';

    public const AUTO_REPLY_MODE_RULES_ONLY = 'rules_only';

    public const CONNECTION_STATUS_CONNECTED = 'connected';

    public const CONNECTION_STATUS_NOT_CONNECTED = 'not_connected';

    public const CONNECTION_STATUS_UNSUPPORTED = 'unsupported';

    public const WEBHOOK_STATUS_INSTALLED = 'installed';

    public const WEBHOOK_STATUS_NOT_INSTALLED = 'not_installed';

    public const WEBHOOK_STATUS_UNSUPPORTED = 'unsupported';

    public const CONNECTION_ERROR_NOT_CHECKED = 'Проверка ещё не выполнялась';

    public const CONNECTION_ERROR_UNSUPPORTED = 'Проверка подключения для этого типа канала пока не поддерживается';

    public const CONNECTION_ERROR_DISABLED = 'Канал выключен в админке';

    public const CONNECTION_ERROR_NO_TOKEN = 'Нет токена';

    public const CONNECTION_ERROR_STALE = 'Данные проверки устарели';

    public const CONNECTION_ERROR_GATEWAY_STALE = 'Gateway не отвечает';

    public const CONNECTION_ERROR_EXPECTED_URL_CHANGED = 'Ожидаемый webhook URL изменился. Нужно выполнить проверку или переустановить webhook.';

    public const CONNECTION_CHECK_FRESH_FOR_MINUTES = 10;

    public const GATEWAY_HEARTBEAT_FRESH_FOR_MINUTES = 2;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'platform',
        'connection_type',
        'channel_connection_type_id',
        'credentials',
        'bot_token_present',
        'bot_external_id',
        'bot_username',
        'bot_name',
        'bot_profile_url',
        'auto_reply_mode',
        'last_webhook_received_at',
        'last_reply_sent_at',
        'last_error_at',
        'last_error_message',
        'is_active',
        'is_hidden',
        'sync_external_outgoing_enabled',
        'telegram_account_media_auto_download_max_bytes',
        'connection_status',
        'webhook_status',
        'connection_checked_at',
        'connection_error_message',
        'provider_webhook_url',
        'expected_webhook_url',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_hidden' => 'boolean',
        'sync_external_outgoing_enabled' => 'boolean',
        'telegram_account_media_auto_download_max_bytes' => 'integer',
        'channel_connection_type_id' => 'integer',
        'bot_token_present' => 'boolean',
        'credentials' => 'encrypted:array',
        'last_webhook_received_at' => 'datetime',
        'last_reply_sent_at' => 'datetime',
        'last_error_at' => 'datetime',
        'connection_checked_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Channel $channel): void {
            $channel->syncLegacyConnectionFieldsFromType();
            $channel->syncConnectionTypeFromLegacyFields();
            $channel->syncTokenPresenceFromCredentials();
            $channel->syncConnectionStatusFromLocalState();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function platformOptions(): array
    {
        return [
            self::PLATFORM_TELEGRAM => 'Telegram',
            self::PLATFORM_MAX => 'MAX',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function connectionTypeOptions(): array
    {
        return [
            self::CONNECTION_TYPE_BOT => 'Bot',
        ];
    }

    /**
     * @return list<string>
     */
    public static function supportedConnectionTypes(): array
    {
        return [
            self::CONNECTION_TYPE_BOT,
            self::CONNECTION_TYPE_ACCOUNT,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function autoReplyModeOptions(): array
    {
        return [
            self::AUTO_REPLY_MODE_RULES_ONLY => 'Только правила',
        ];
    }

    protected function displayTitle(): Attribute
    {
        return Attribute::make(
            get: fn (): string => sprintf(
                '#%d %s (%s)',
                $this->id,
                $this->name,
                self::platformOptions()[$this->platform] ?? $this->platform,
            ),
        );
    }

    public function getToken(): ?string
    {
        $token = data_get($this->readableCredentials(), self::CREDENTIAL_TOKEN);

        return filled($token) ? (string) $token : null;
    }

    public function isBotConnection(): bool
    {
        return $this->resolvedConnectionKind() === self::CONNECTION_TYPE_BOT;
    }

    public function isAccountConnection(): bool
    {
        return $this->resolvedConnectionKind() === self::CONNECTION_TYPE_ACCOUNT;
    }

    public function getConnectionTypeLabel(): string
    {
        if ($this->connectionTypeDefinition instanceof ChannelConnectionType) {
            return $this->connectionTypeDefinition->name;
        }

        return match ($this->resolvedConnectionKind()) {
            self::CONNECTION_TYPE_BOT => 'Bot',
            self::CONNECTION_TYPE_ACCOUNT => 'Account',
            default => (string) $this->resolvedConnectionKind(),
        };
    }

    public function resolvedConnectionKind(): string
    {
        $definition = $this->resolvedConnectionTypeDefinition();

        if ($definition instanceof ChannelConnectionType) {
            return (string) $definition->connection_kind;
        }

        return (string) $this->connection_type;
    }

    protected function syncLegacyConnectionFieldsFromType(): void
    {
        $definition = $this->resolvedConnectionTypeDefinition();

        if (! $definition instanceof ChannelConnectionType) {
            return;
        }

        $this->forceFill([
            'platform' => $definition->platform,
            'connection_type' => $definition->connection_kind,
        ]);
    }

    protected function syncConnectionTypeFromLegacyFields(): void
    {
        if (filled($this->channel_connection_type_id)) {
            return;
        }

        $typeId = ChannelConnectionType::resolveIdFor(
            (string) $this->platform,
            (string) $this->connection_type,
        );

        if ($typeId !== null) {
            $this->forceFill(['channel_connection_type_id' => $typeId]);
        }
    }

    protected function resolvedConnectionTypeDefinition(): ?ChannelConnectionType
    {
        if (filled($this->channel_connection_type_id)) {
            if ($this->relationLoaded('connectionTypeDefinition')) {
                $definition = $this->getRelation('connectionTypeDefinition');

                if ($definition instanceof ChannelConnectionType && (int) $definition->id === (int) $this->channel_connection_type_id) {
                    return $definition;
                }
            }

            try {
                return ChannelConnectionType::query()->find($this->channel_connection_type_id);
            } catch (Throwable) {
                return null;
            }
        }

        if ($this->relationLoaded('connectionTypeDefinition')) {
            $definition = $this->getRelation('connectionTypeDefinition');

            return $definition instanceof ChannelConnectionType ? $definition : null;
        }

        return null;
    }

    public function hasBotTokenConfigured(): bool
    {
        return (bool) $this->bot_token_present;
    }

    public function getWebhookSecret(): ?string
    {
        $secret = data_get($this->readableCredentials(), self::CREDENTIAL_WEBHOOK_SECRET);

        return filled($secret) ? (string) $secret : null;
    }

    public function usesLegacyAutoReplyFallback(): bool
    {
        return false;
    }

    public function usesRulesOnlyAutoReply(): bool
    {
        return in_array(
            $this->auto_reply_mode ?? self::AUTO_REPLY_MODE_RULES_ONLY,
            [self::AUTO_REPLY_MODE_RULES_ONLY, self::AUTO_REPLY_MODE_LEGACY_DEFAULT],
            true,
        );
    }

    public function getAutoReplyModeLabel(): string
    {
        $autoReplyMode = $this->auto_reply_mode ?? self::AUTO_REPLY_MODE_RULES_ONLY;

        if ($autoReplyMode === self::AUTO_REPLY_MODE_LEGACY_DEFAULT) {
            $autoReplyMode = self::AUTO_REPLY_MODE_RULES_ONLY;
        }

        return self::autoReplyModeOptions()[$autoReplyMode]
            ?? (string) $autoReplyMode;
    }

    public function putCredential(string $key, mixed $value): static
    {
        $credentials = $this->readableCredentials();

        Arr::set($credentials, $key, $value);

        $this->credentials = $credentials;
        $this->syncTokenPresenceFromCredentials();

        return $this;
    }

    public function syncTokenPresenceFromCredentials(): static
    {
        $this->bot_token_present = filled(data_get($this->readableCredentials(), self::CREDENTIAL_TOKEN));

        return $this;
    }

    public function getBotUsernameLabel(): ?string
    {
        if (! filled($this->bot_username)) {
            return null;
        }

        return '@'.ltrim((string) $this->bot_username, '@');
    }

    public function getTelegramAccountIdentityLabel(): ?string
    {
        if (! $this->isAccountConnection()) {
            return null;
        }

        $username = $this->telegramAccountIdentityValue('username');

        if ($username !== null) {
            return '@'.ltrim($username, '@');
        }

        $id = $this->telegramAccountIdentityValue('id');

        if ($id !== null) {
            return 'ID '.$id;
        }

        return $this->telegramAccountIdentityValue('display_name');
    }

    public function getTelegramAccountDisplayNameLabel(): ?string
    {
        if (! $this->isAccountConnection()) {
            return null;
        }

        return $this->telegramAccountIdentityValue('display_name')
            ?? $this->getTelegramAccountIdentityLabel();
    }

    public function getTelegramAccountExternalIdLabel(): ?string
    {
        if (! $this->isAccountConnection()) {
            return null;
        }

        return $this->telegramAccountIdentityValue('id');
    }

    public function getTelegramAccountProfileUrl(): ?string
    {
        if (! $this->isAccountConnection() || $this->platform !== self::PLATFORM_TELEGRAM) {
            return null;
        }

        $username = $this->telegramAccountIdentityValue('username');

        return $username === null ? null : 'https://t.me/'.ltrim($username, '@');
    }

    public function getBotProfileUrl(): ?string
    {
        if (filled($this->bot_profile_url)) {
            return (string) $this->bot_profile_url;
        }

        if (! filled($this->bot_username)) {
            return null;
        }

        return match ($this->platform) {
            self::PLATFORM_TELEGRAM => 'https://t.me/'.ltrim((string) $this->bot_username, '@'),
            self::PLATFORM_MAX => 'https://max.ru/'.ltrim((string) $this->bot_username, '@'),
            default => null,
        };
    }

    public function getWebhookStatusLabel(): string
    {
        if ($this->isAccountConnection()) {
            return 'Не используется';
        }

        if ($this->hasUnreadableCredentials()) {
            return 'Ошибка настроек';
        }

        return filled($this->getWebhookSecret()) ? 'Настроен' : 'Не настроен';
    }

    public function supportsConnectionCheck(): bool
    {
        return in_array($this->platform, [self::PLATFORM_TELEGRAM, self::PLATFORM_MAX], true)
            && $this->isBotConnection();
    }

    public function getConnectionStatusLabel(?string $status = null): string
    {
        return match ($status ?? $this->connection_status) {
            self::CONNECTION_STATUS_CONNECTED => 'Подключен',
            self::CONNECTION_STATUS_UNSUPPORTED => 'Не поддерживается',
            default => 'Не подключен',
        };
    }

    public function getConnectionStatusColor(?string $status = null): string
    {
        return match ($status ?? $this->connection_status) {
            self::CONNECTION_STATUS_CONNECTED => 'success',
            self::CONNECTION_STATUS_UNSUPPORTED => 'gray',
            default => 'danger',
        };
    }

    public function getLiveWebhookStatusLabel(?string $status = null): string
    {
        return match ($status ?? $this->webhook_status) {
            self::WEBHOOK_STATUS_INSTALLED => 'Установлен',
            self::WEBHOOK_STATUS_UNSUPPORTED => 'Не поддерживается',
            default => 'Не установлен',
        };
    }

    public function getLiveWebhookStatusColor(?string $status = null): string
    {
        return match ($status ?? $this->webhook_status) {
            self::WEBHOOK_STATUS_INSTALLED => 'success',
            self::WEBHOOK_STATUS_UNSUPPORTED => 'gray',
            default => 'danger',
        };
    }

    public function syncConnectionStatusFromLocalState(): void
    {
        if (! $this->supportsConnectionCheck()) {
            $this->forceFill([
                'connection_status' => self::CONNECTION_STATUS_UNSUPPORTED,
                'webhook_status' => self::WEBHOOK_STATUS_UNSUPPORTED,
                'connection_checked_at' => null,
                'connection_error_message' => self::CONNECTION_ERROR_UNSUPPORTED,
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);

            return;
        }

        if (! $this->is_active) {
            $this->forceFill([
                'connection_status' => self::CONNECTION_STATUS_NOT_CONNECTED,
                'webhook_status' => self::WEBHOOK_STATUS_NOT_INSTALLED,
                'connection_checked_at' => null,
                'connection_error_message' => self::CONNECTION_ERROR_DISABLED,
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);

            return;
        }

        if (! $this->bot_token_present) {
            $this->forceFill([
                'connection_status' => self::CONNECTION_STATUS_NOT_CONNECTED,
                'webhook_status' => self::WEBHOOK_STATUS_NOT_INSTALLED,
                'connection_checked_at' => null,
                'connection_error_message' => self::CONNECTION_ERROR_NO_TOKEN,
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);

            return;
        }

        if ($this->exists && $this->connectionAffectingFieldsChanged()) {
            $this->forceFill([
                'connection_status' => self::CONNECTION_STATUS_NOT_CONNECTED,
                'webhook_status' => self::WEBHOOK_STATUS_NOT_INSTALLED,
                'connection_checked_at' => null,
                'connection_error_message' => self::CONNECTION_ERROR_NOT_CHECKED,
                'provider_webhook_url' => null,
                'expected_webhook_url' => null,
            ]);
        }
    }

    protected function connectionAffectingFieldsChanged(): bool
    {
        return $this->isDirty('platform')
            || $this->isDirty('connection_type')
            || $this->isDirty('channel_connection_type_id')
            || $this->isDirty('is_active')
            || $this->isDirty('credentials');
    }

    public function connectionTypeDefinition(): BelongsTo
    {
        return $this->belongsTo(ChannelConnectionType::class, 'channel_connection_type_id');
    }

    public function runtimeState(): HasOne
    {
        return $this->hasOne(ChannelRuntimeState::class);
    }

    public function peerSyncStates(): HasMany
    {
        return $this->hasMany(ChannelPeerSyncState::class);
    }

    public function getWebhookStatusColor(): string
    {
        if ($this->isAccountConnection()) {
            return 'gray';
        }

        if ($this->hasUnreadableCredentials()) {
            return 'danger';
        }

        return filled($this->getWebhookSecret()) ? 'success' : 'gray';
    }

    public function getHealthStatusLabel(): string
    {
        if ($this->hasUnreadableCredentials()) {
            return 'Ошибка настроек';
        }

        if ($this->isAccountConnection()) {
            return $this->getAccountHealthStatusLabel();
        }

        if (! $this->is_active) {
            return 'Отключен';
        }

        if (! filled($this->getWebhookSecret())) {
            return 'Без webhook';
        }

        if ($this->supportsConnectionCheck()) {
            if (! $this->hasFreshConnectionCheck()) {
                return $this->connection_checked_at === null ? 'Не проверен' : 'Проверка устарела';
            }

            if (! $this->hasCurrentInstalledWebhook()) {
                return 'Не подключен';
            }
        }

        if ($this->last_error_at !== null && ($this->last_reply_sent_at === null || $this->last_error_at->greaterThanOrEqualTo($this->last_reply_sent_at))) {
            return 'Ошибка';
        }

        if ($this->last_reply_sent_at !== null) {
            return 'Работает';
        }

        if ($this->last_webhook_received_at !== null) {
            return 'Webhook';
        }

        return 'Не проверен';
    }

    public function getHealthStatusColor(): string
    {
        if ($this->isAccountConnection()) {
            return match ($this->getHealthStatusLabel()) {
                'Работает' => 'success',
                'Синхронизация' => 'info',
                'Авторизация', 'Ограниченно' => 'warning',
                'Ошибка', 'Отозван', 'Gateway не отвечает' => 'danger',
                'Отключен', 'Не авторизован' => 'gray',
                default => 'gray',
            };
        }

        return match ($this->getHealthStatusLabel()) {
            'Работает' => 'success',
            'Webhook' => 'info',
            'Не проверен' => 'gray',
            'Без webhook', 'Проверка устарела' => 'warning',
            'Ошибка', 'Ошибка настроек', 'Не подключен' => 'danger',
            'Отключен' => 'gray',
            default => 'gray',
        };
    }

    public function isReadyForConstructorAutoReplies(): bool
    {
        if (! $this->exists || ! $this->is_active) {
            return false;
        }

        if ($this->hasUnreadableCredentials()) {
            return false;
        }

        if ($this->isAccountConnection()) {
            return $this->hasReadyTelegramAccountGatewayOutgoingReplies();
        }

        return $this->isBotConnection()
            && $this->hasBotTokenConfigured()
            && $this->hasCurrentInstalledWebhook();
    }

    public function hasReadyTelegramAccountGatewayOutgoingReplies(): bool
    {
        if (! $this->exists) {
            return false;
        }

        return app(ResolveTelegramAccountGatewayDiagnosticsAction::class)
            ->handle($this)
            ->isOutgoingReplyReady;
    }

    public function hasFreshConnectionCheck(): bool
    {
        return $this->connection_checked_at !== null
            && $this->connection_checked_at->greaterThanOrEqualTo(now()->subMinutes(self::CONNECTION_CHECK_FRESH_FOR_MINUTES));
    }

    public function hasCurrentInstalledWebhook(): bool
    {
        return $this->supportsConnectionCheck()
            && $this->hasFreshConnectionCheck()
            && $this->hasWebhookUrlForCurrentApp()
            && $this->connection_status === self::CONNECTION_STATUS_CONNECTED
            && $this->webhook_status === self::WEBHOOK_STATUS_INSTALLED;
    }

    public function hasWebhookUrlForCurrentApp(): bool
    {
        $currentWebhookUrl = $this->currentWebhookUrl();

        if ($currentWebhookUrl === null) {
            return false;
        }

        if (filled($this->expected_webhook_url) && ! $this->webhookUrlsMatch($currentWebhookUrl, (string) $this->expected_webhook_url)) {
            return false;
        }

        if (filled($this->provider_webhook_url) && ! $this->webhookUrlsMatch($currentWebhookUrl, (string) $this->provider_webhook_url)) {
            return false;
        }

        return true;
    }

    protected function currentWebhookUrl(): ?string
    {
        if (! $this->exists || ! $this->supportsConnectionCheck()) {
            return null;
        }

        $baseUrl = rtrim((string) config('app.url'), '/');

        if ($baseUrl === '') {
            return null;
        }

        $path = match ($this->platform) {
            self::PLATFORM_TELEGRAM => route('webhooks.telegram.handle', ['channel' => $this], absolute: false),
            self::PLATFORM_MAX => route('webhooks.max.handle', ['channel' => $this], absolute: false),
            default => null,
        };

        return $path === null ? null : $baseUrl.$path;
    }

    protected function webhookUrlsMatch(string $expectedUrl, string $actualUrl): bool
    {
        return rtrim($expectedUrl, '/') === rtrim($actualUrl, '/');
    }

    public function hasFreshTelegramAccountGatewayHeartbeat(): bool
    {
        if (! $this->isAccountConnection() || $this->platform !== self::PLATFORM_TELEGRAM) {
            return false;
        }

        $this->loadMissing('runtimeState');

        $runtimeState = $this->runtimeState;

        return $runtimeState instanceof ChannelRuntimeState
            && $runtimeState->last_gateway_heartbeat_at !== null
            && $runtimeState->last_gateway_heartbeat_at->greaterThanOrEqualTo(now()->subMinutes(self::GATEWAY_HEARTBEAT_FRESH_FOR_MINUTES));
    }

    private function telegramAccountIdentityValue(string $key): ?string
    {
        $value = data_get($this->runtimeState?->runtime_payload, "account.{$key}");

        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    protected function getAccountHealthStatusLabel(): string
    {
        if (! $this->is_active) {
            return 'Отключен';
        }

        $runtimeState = $this->runtimeState;

        if (! $runtimeState instanceof ChannelRuntimeState) {
            return 'Не авторизован';
        }

        if ($runtimeState->auth_status === ChannelRuntimeState::AUTH_STATUS_FAILED) {
            return 'Ошибка';
        }

        if ($runtimeState->auth_status === ChannelRuntimeState::AUTH_STATUS_REVOKED) {
            return 'Отозван';
        }

        if (
            $runtimeState->auth_status !== ChannelRuntimeState::AUTH_STATUS_AUTHORIZED
            || $runtimeState->authorization_state !== ChannelRuntimeState::AUTHORIZATION_STATE_READY
        ) {
            return 'Авторизация';
        }

        if (! $this->hasFreshTelegramAccountGatewayHeartbeat()) {
            return self::CONNECTION_ERROR_GATEWAY_STALE;
        }

        return match ($runtimeState->sync_status) {
            ChannelRuntimeState::SYNC_STATUS_LIVE => 'Работает',
            ChannelRuntimeState::SYNC_STATUS_BACKFILL_IN_PROGRESS => 'Синхронизация',
            ChannelRuntimeState::SYNC_STATUS_DEGRADED => 'Ограниченно',
            ChannelRuntimeState::SYNC_STATUS_FAILED => 'Ошибка',
            default => 'Авторизация',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function readableCredentials(): array
    {
        try {
            $credentials = $this->credentials;
        } catch (DecryptException) {
            return [];
        }

        return is_array($credentials) ? $credentials : [];
    }

    public function hasUnreadableCredentials(): bool
    {
        try {
            $this->credentials;
        } catch (DecryptException) {
            return true;
        }

        return false;
    }

    public function markWebhookReceived(?string $currentWebhookUrl = null): static
    {
        $state = [
            'last_webhook_received_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ];

        if (
            $currentWebhookUrl !== null
            && $this->supportsConnectionCheck()
            && $this->is_active
            && $this->hasBotTokenConfigured()
        ) {
            $state = array_merge($state, [
                'connection_status' => self::CONNECTION_STATUS_CONNECTED,
                'webhook_status' => self::WEBHOOK_STATUS_INSTALLED,
                'connection_checked_at' => now(),
                'connection_error_message' => null,
                'provider_webhook_url' => $currentWebhookUrl,
                'expected_webhook_url' => $currentWebhookUrl,
            ]);
        }

        $this->persistOperationalState($state);

        return $this;
    }

    public function markReplySent(): static
    {
        $this->persistOperationalState([
            'last_reply_sent_at' => now(),
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        return $this;
    }

    public function markError(string|Throwable $error): static
    {
        $message = $error instanceof Throwable ? $error->getMessage() : $error;

        $this->persistOperationalState([
            'last_error_at' => now(),
            'last_error_message' => Str::limit(trim($message), 1000),
        ]);

        return $this;
    }

    public function clearOperationalError(): static
    {
        $this->persistOperationalState([
            'last_error_at' => null,
            'last_error_message' => null,
        ]);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persistOperationalState(array $attributes): void
    {
        $this->forceFill($attributes);

        if (! $this->hasUnreadableCredentials()) {
            try {
                $this->saveQuietly();

                return;
            } catch (DecryptException) {
                // Fall through to a direct operational-state update when legacy
                // encrypted credentials cannot be compared during dirty checks.
            }
        }

        if ($this->usesTimestamps()) {
            $this->setUpdatedAt($this->freshTimestamp());
            $attributes[$this->getUpdatedAtColumn()] = true;
        }

        $columns = array_keys($attributes);

        DB::table($this->getTable())
            ->where($this->getKeyName(), $this->getKey())
            ->update(Arr::only($this->getAttributes(), $columns));

        $this->syncOriginalAttributes($columns);
    }

    public function activityLogs(): HasMany
    {
        return $this->hasMany(ChannelActivityLog::class);
    }

    public function contactIdentities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function dialogs(): HasMany
    {
        return $this->hasMany(Dialog::class);
    }

    public function bitrix24OpenLineRoutes(): HasMany
    {
        return $this->hasMany(Bitrix24OpenLineRoute::class);
    }

    public function autoReplyRules(): BelongsToMany
    {
        return $this->belongsToMany(AutoReplyRule::class, 'auto_reply_rule_channels')
            ->withPivot(['button_type', 'button_text', 'button_url'])
            ->withTimestamps();
    }

    public function scenarioBindings(): HasMany
    {
        return $this->hasMany(ScenarioChannelBinding::class);
    }
}
