<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\User;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24OpenLineAutoSetupException;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

class ViewBitrix24Connection extends ViewRecord
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    public string $webhookEventCallbackTypeFilter = '';

    public string $webhookEventProcessingStatusFilter = '';

    public string $syncLogStatusFilter = '';

    /**
     * @var array<int, array{status:string,connector_code:string,line_id:string,source_id:string}>
     */
    public array $openLineRouteForms = [];

    /**
     * @var array{application_name:string}
     */
    public array $applicationNameForm = [
        'application_name' => '',
    ];

    /**
     * @var array{telegram_source_id:string,max_source_id:string,telegram_connector_code:string,max_connector_code:string,default_assigned_user_id:string,default_deal_category_id:string,default_deal_stage_id:string}
     */
    public array $profileSettingsForm = [
        'telegram_source_id' => '',
        'max_source_id' => '',
        'telegram_connector_code' => '',
        'max_connector_code' => '',
        'default_assigned_user_id' => '',
        'default_deal_category_id' => '',
        'default_deal_stage_id' => '',
    ];

    public ?string $openLineRouteErrorMessage = null;

    public ?string $profileSettingsErrorMessage = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->reloadOpenLineRouteForms();
        $this->reloadApplicationNameForm();
        $this->reloadProfileSettingsForm();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Настройки Bitrix24';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Настройки Bitrix24';
    }

    public function getSubheading(): ?string
    {
        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection) {
            return null;
        }

        return sprintf(
            '%s: подключение, маршруты открытых линий, callback-и и sync-логи.',
            $record->portal_domain,
        );
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    public function getWebhookEventCards(): array
    {
        $query = $this->getRecord()->webhookEvents()
            ->orderByDesc('id');

        if ($this->webhookEventCallbackTypeFilter !== '') {
            $query->where('callback_type', $this->webhookEventCallbackTypeFilter);
        }

        if ($this->webhookEventProcessingStatusFilter !== '') {
            $query->where('processing_status', $this->webhookEventProcessingStatusFilter);
        }

        return $query
            ->limit(20)
            ->get()
            ->map(fn (Bitrix24WebhookEvent $event): array => [
                'created_at_label' => $this->formatTimestamp($event->created_at),
                'callback_type_label' => Bitrix24ConnectionResource::formatWebhookEventCallbackType($event->callback_type),
                'processing_status_label' => Bitrix24ConnectionResource::formatWebhookEventProcessingStatus($event->processing_status),
                'processing_status_tone' => Bitrix24ConnectionResource::getWebhookEventProcessingStatusTone($event->processing_status),
                'event_name' => filled($event->event_name) ? (string) $event->event_name : '—',
                'attempts' => $event->attempts,
                'failed_at_label' => $this->formatTimestamp($event->failed_at),
                'failure_reason' => filled($event->failure_reason) ? (string) $event->failure_reason : '—',
            ])
            ->all();
    }

    /**
     * @return list<array<string, string|int|null>>
     */
    public function getSyncLogCards(): array
    {
        $query = $this->getRecord()->syncLogs()
            ->orderByDesc('id');

        if ($this->syncLogStatusFilter !== '') {
            $query->where('status', $this->syncLogStatusFilter);
        }

        return $query
            ->limit(20)
            ->get()
            ->map(fn (Bitrix24SyncLog $log): array => [
                'created_at_label' => $this->formatTimestamp($log->created_at),
                'direction_label' => Bitrix24ConnectionResource::formatSyncLogDirection($log->direction),
                'status_label' => Bitrix24ConnectionResource::formatSyncLogStatus($log->status),
                'status_tone' => Bitrix24ConnectionResource::getSyncLogStatusTone($log->status),
                'operation' => filled($log->operation) ? (string) $log->operation : '—',
                'entity_type' => filled($log->entity_type) ? (string) $log->entity_type : '—',
                'entity_id' => filled($log->entity_id) ? (string) $log->entity_id : '—',
                'http_status' => $log->http_status,
                'error_code' => filled($log->error_code) ? (string) $log->error_code : '—',
                'error_message' => filled($log->error_message) ? (string) $log->error_message : '—',
            ])
            ->all();
    }

    public function canEditOpenLineRoutes(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $this->getRecord() instanceof Bitrix24Connection
            && $user->can('update', $this->getRecord());
    }

    public function canAutoSetupOpenLineRoutes(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->isSuperadmin()
            && $this->getRecord() instanceof Bitrix24Connection;
    }

    public function canEditApplicationName(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->isSuperadmin()
            && $this->getRecord() instanceof Bitrix24Connection;
    }

    public function canEditProfileSettings(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && $user->isSuperadmin()
            && $this->getBitrix24Profile() instanceof Bitrix24Profile;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOpenLineRouteCards(): array
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            return [];
        }

        $routes = Bitrix24OpenLineRoute::query()
            ->with(['bitrix24Profile', 'channel'])
            ->where('bitrix24_profile_id', $profile->id)
            ->get()
            ->keyBy('channel_id');

        return $this->getOpenLineRouteChannels()
            ->map(function (Channel $channel) use ($profile, $routes): array {
                /** @var Bitrix24OpenLineRoute|null $route */
                $route = $routes->get($channel->id);
                $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);
                $form = $this->openLineRouteForms[$channel->id] ?? $this->defaultOpenLineRouteForm($profile, $channel, $route);
                $status = (string) ($route?->status ?? '');
                $autoSetup = $this->resolveOpenLineAutoSetupState($profile, $channel, $route);

                return [
                    'channel_id' => $channel->id,
                    'channel_title' => sprintf('#%d %s', $channel->id, $channel->name),
                    'channel_summary' => sprintf(
                        '%s, %s',
                        Channel::platformOptions()[$channel->platform] ?? $channel->platform,
                        $channel->getConnectionTypeLabel(),
                    ),
                    'channel_type_label' => Bitrix24ConnectionResource::formatOpenLineRouteChannelType($channelType),
                    'route_id' => $route?->id,
                    'route_status_label' => $route instanceof Bitrix24OpenLineRoute
                        ? Bitrix24ConnectionResource::formatOpenLineRouteStatus($status)
                        : 'Требует настройки',
                    'route_status_tone' => $route instanceof Bitrix24OpenLineRoute
                        ? Bitrix24ConnectionResource::getOpenLineRouteStatusTone($status)
                        : 'warning',
                    'connector_code' => filled($route?->connector_code) ? (string) $route?->connector_code : '—',
                    'line_id' => filled($route?->line_id) ? (string) $route?->line_id : '—',
                    'source_id' => filled($route?->source_id) ? (string) $route?->source_id : '—',
                    'line_owner_label' => $this->resolveLineOwnerLabel($profile, $channel, $route, $form),
                    'last_error_message' => filled($route?->last_error_message) ? (string) $route?->last_error_message : 'Ошибок не было',
                    'auto_setup_visible' => $autoSetup['visible'],
                    'auto_setup_enabled' => $autoSetup['enabled'],
                    'auto_setup_label' => $autoSetup['label'],
                    'auto_setup_reason' => $autoSetup['reason'],
                ];
            })
            ->values()
            ->all();
    }

    public function saveOpenLineRoute(int|string $channelId): void
    {
        abort_unless($this->canEditOpenLineRoutes(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failOpenLineRouteSave('У подключения Bitrix24 нет профиля. Сначала выровняйте профиль подключения.');

            return;
        }

        $channel = Channel::query()->find($channelId);

        if (! $channel instanceof Channel) {
            $this->failOpenLineRouteSave('Канал не найден.');

            return;
        }

        $form = $this->normalizeOpenLineRouteForm($this->openLineRouteForms[$channel->id] ?? []);
        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->first();

        if (! $this->validateOpenLineRouteForm($profile, $channel, $route, $form)) {
            return;
        }

        $user = auth()->user();

        try {
            $route ??= new Bitrix24OpenLineRoute([
                'bitrix24_profile_id' => $profile->id,
                'channel_id' => $channel->id,
                'created_by_user_id' => $user instanceof User ? $user->id : null,
            ]);

            $route->fill([
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => $this->nullableFormValue($form['connector_code']),
                'line_id' => $this->nullableFormValue($form['line_id']),
                'source_id' => $this->nullableFormValue($form['source_id']),
                'status' => $form['status'],
                'updated_by_user_id' => $user instanceof User ? $user->id : null,
            ]);

            $route->save();
        } catch (QueryException) {
            $this->failOpenLineRouteSave('Открытая линия уже занята другим рабочим маршрутом.');

            return;
        }

        $this->openLineRouteErrorMessage = null;
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Маршрут открытой линии сохранён')
            ->send();
    }

    public function setupOpenLineRoute(int|string $channelId): void
    {
        abort_unless($this->canAutoSetupOpenLineRoutes(), 403);

        $record = $this->getRecord();
        $profile = $this->getBitrix24Profile();
        $channel = Channel::query()->find($channelId);

        if (! $record instanceof Bitrix24Connection || ! $profile instanceof Bitrix24Profile) {
            $this->failOpenLineRouteSave('У подключения Bitrix24 нет профиля. Сначала выровняйте профиль подключения.');

            return;
        }

        if (! $channel instanceof Channel) {
            $this->failOpenLineRouteSave('Канал не найден.');

            return;
        }

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->first();
        $refreshExistingRoute = $route instanceof Bitrix24OpenLineRoute && $route->isUsable();

        try {
            if ($refreshExistingRoute) {
                app(AutoSetupBitrix24OpenLineRouteAction::class)->refreshConnectorRegistration($record, $route);
            } else {
                app(AutoSetupBitrix24OpenLineRouteAction::class)->handle($record, $channel, auth()->user());
            }
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->failOpenLineRouteSave($exception->getMessage());

            return;
        }

        $this->openLineRouteErrorMessage = null;
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title($refreshExistingRoute ? 'Карточка соединителя обновлена' : 'Открытая линия настроена')
            ->send();
    }

    public function saveApplicationName(): void
    {
        abort_unless($this->canEditApplicationName(), 403);

        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection) {
            $this->failApplicationNameSave('Подключение Bitrix24 не найдено.');

            return;
        }

        $applicationName = trim((string) ($this->applicationNameForm['application_name'] ?? ''));

        if ($applicationName === '') {
            $this->failApplicationNameSave('Название приложения не заполнено.');

            return;
        }

        if (mb_strlen($applicationName) > 120) {
            $this->failApplicationNameSave('Название приложения должно быть не длиннее 120 символов.');

            return;
        }

        $record->forceFill([
            'application_name' => $applicationName,
        ])->save();

        $refreshed = 0;
        $failed = 0;

        $routes = Bitrix24OpenLineRoute::query()
            ->with(['bitrix24Profile', 'channel'])
            ->where('bitrix24_profile_id', $record->profile_id)
            ->whereIn('status', Bitrix24OpenLineRoute::usableStatuses())
            ->get();

        foreach ($routes as $route) {
            try {
                app(AutoSetupBitrix24OpenLineRouteAction::class)->refreshConnectorRegistration($record, $route);
                $refreshed++;
            } catch (Bitrix24OpenLineAutoSetupException) {
                $failed++;
            }
        }

        $record->refresh();
        $this->reloadApplicationNameForm();
        $this->reloadOpenLineRouteForms();

        if ($failed > 0) {
            Notification::make()
                ->warning()
                ->title('Название сохранено, часть карточек Bitrix24 не обновилась')
                ->body(sprintf('Обновлено: %d. Ошибок: %d. Проверьте ошибки в маршрутах ОЛ.', $refreshed, $failed))
                ->send();

            return;
        }

        Notification::make()
            ->success()
            ->title('Название приложения сохранено')
            ->body($refreshed > 0 ? sprintf('Карточки контакт-центра Bitrix24 обновлены: %d.', $refreshed) : null)
            ->send();
    }

    public function saveProfileSettings(): void
    {
        abort_unless($this->canEditProfileSettings(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failProfileSettingsSave('Профиль Bitrix24 не найден.');

            return;
        }

        $assignedUserId = $this->nullableIntegerFormValue($this->profileSettingsForm['default_assigned_user_id'] ?? '');
        $dealCategoryId = $this->nullableIntegerFormValue($this->profileSettingsForm['default_deal_category_id'] ?? '');
        $dealStageId = $this->nullableFormValue(trim((string) ($this->profileSettingsForm['default_deal_stage_id'] ?? '')));

        if ($this->filledButInvalidInteger($this->profileSettingsForm['default_assigned_user_id'] ?? '')) {
            $this->failProfileSettingsSave('Default assigned user ID должен быть числом.');

            return;
        }

        if ($this->filledButInvalidInteger($this->profileSettingsForm['default_deal_category_id'] ?? '')) {
            $this->failProfileSettingsSave('Default deal category ID должен быть числом.');

            return;
        }

        $profile->fill([
            'telegram_source_id' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['telegram_source_id'] ?? ''))),
            'max_source_id' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['max_source_id'] ?? ''))),
            'telegram_connector_code' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['telegram_connector_code'] ?? ''))),
            'max_connector_code' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['max_connector_code'] ?? ''))),
            'default_assigned_user_id' => $assignedUserId,
            'default_deal_category_id' => $dealCategoryId,
            'default_deal_stage_id' => $dealStageId,
        ]);
        $profile->save();

        $this->profileSettingsErrorMessage = null;
        $this->getRecord()->refresh();
        $this->reloadProfileSettingsForm();
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Настройки профиля Bitrix24 сохранены')
            ->send();
    }

    public function reloadOpenLineRouteForms(): void
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->openLineRouteForms = [];

            return;
        }

        $routes = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->get()
            ->keyBy('channel_id');

        $this->openLineRouteForms = $this->getOpenLineRouteChannels()
            ->mapWithKeys(fn (Channel $channel): array => [
                $channel->id => $this->defaultOpenLineRouteForm($profile, $channel, $routes->get($channel->id)),
            ])
            ->all();
    }

    public function reloadApplicationNameForm(): void
    {
        $record = $this->getRecord();

        $this->applicationNameForm = [
            'application_name' => $record instanceof Bitrix24Connection ? (string) $record->application_name : '',
        ];
    }

    public function reloadProfileSettingsForm(): void
    {
        $profile = $this->getBitrix24Profile();

        $this->profileSettingsForm = [
            'telegram_source_id' => $profile instanceof Bitrix24Profile ? (string) ($profile->telegram_source_id ?? '') : '',
            'max_source_id' => $profile instanceof Bitrix24Profile ? (string) ($profile->max_source_id ?? '') : '',
            'telegram_connector_code' => $profile instanceof Bitrix24Profile ? (string) ($profile->telegram_connector_code ?? '') : '',
            'max_connector_code' => $profile instanceof Bitrix24Profile ? (string) ($profile->max_connector_code ?? '') : '',
            'default_assigned_user_id' => $profile instanceof Bitrix24Profile && $profile->default_assigned_user_id !== null ? (string) $profile->default_assigned_user_id : '',
            'default_deal_category_id' => $profile instanceof Bitrix24Profile && $profile->default_deal_category_id !== null ? (string) $profile->default_deal_category_id : '',
            'default_deal_stage_id' => $profile instanceof Bitrix24Profile ? (string) ($profile->default_deal_stage_id ?? '') : '',
        ];
    }

    protected function formatTimestamp(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '—';
        }

        return $value->format('d.m.Y H:i:s');
    }

    protected function getBitrix24Profile(): ?Bitrix24Profile
    {
        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection) {
            return null;
        }

        $record->loadMissing('profile');

        return $record->profile;
    }

    /**
     * @return Collection<int, Channel>
     */
    protected function getOpenLineRouteChannels(): Collection
    {
        return Channel::query()
            ->orderBy('platform')
            ->orderBy('connection_type')
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array{status:string,connector_code:string,line_id:string,source_id:string}
     */
    protected function defaultOpenLineRouteForm(Bitrix24Profile $profile, Channel $channel, ?Bitrix24OpenLineRoute $route): array
    {
        return [
            'status' => (string) ($route?->status ?? $this->defaultStatusForChannel($channel)),
            'connector_code' => (string) ($route?->connector_code ?? $this->defaultConnectorCodeForChannel($profile, $channel)),
            'line_id' => (string) ($route?->line_id ?? ''),
            'source_id' => (string) ($route?->source_id ?? $this->defaultSourceIdForChannel($profile, $channel)),
        ];
    }

    protected function defaultStatusForChannel(Channel $channel): string
    {
        return Bitrix24OpenLineRoute::channelTypeForChannel($channel) === Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT
            ? Bitrix24OpenLineRoute::STATUS_UNSUPPORTED
            : Bitrix24OpenLineRoute::STATUS_INACTIVE;
    }

    protected function defaultConnectorCodeForChannel(Bitrix24Profile $profile, Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT => (string) ($profile->openLinesConnectorCodeForPlatform(Channel::PLATFORM_TELEGRAM) ?? ''),
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT => 'abc_telegram_account',
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => (string) ($profile->openLinesConnectorCodeForPlatform(Channel::PLATFORM_MAX) ?? ''),
            default => '',
        };
    }

    protected function defaultSourceIdForChannel(Bitrix24Profile $profile, Channel $channel): string
    {
        return match (Bitrix24OpenLineRoute::channelTypeForChannel($channel)) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT => (string) ($profile->sourceIdForPlatform(Channel::PLATFORM_TELEGRAM) ?? ''),
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => (string) ($profile->sourceIdForPlatform(Channel::PLATFORM_MAX) ?? ''),
            default => '',
        };
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{status:string,connector_code:string,line_id:string,source_id:string}
     */
    protected function normalizeOpenLineRouteForm(array $form): array
    {
        return [
            'status' => trim((string) ($form['status'] ?? Bitrix24OpenLineRoute::STATUS_INACTIVE)),
            'connector_code' => trim((string) ($form['connector_code'] ?? '')),
            'line_id' => trim((string) ($form['line_id'] ?? '')),
            'source_id' => trim((string) ($form['source_id'] ?? '')),
        ];
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,source_id:string}  $form
     */
    protected function validateOpenLineRouteForm(
        Bitrix24Profile $profile,
        Channel $channel,
        ?Bitrix24OpenLineRoute $route,
        array $form,
    ): bool {
        if (! array_key_exists($form['status'], Bitrix24ConnectionResource::getOpenLineRouteStatusOptions())) {
            $this->failOpenLineRouteSave('Выбран неизвестный статус маршрута.');

            return false;
        }

        $isUsableStatus = in_array($form['status'], Bitrix24OpenLineRoute::usableStatuses(), true);

        if (
            $isUsableStatus
            && Bitrix24OpenLineRoute::channelTypeForChannel($channel) === Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT
        ) {
            $this->failOpenLineRouteSave('Telegram account пока нельзя сделать рабочим маршрутом открытых линий.');

            return false;
        }

        if ($isUsableStatus && ($form['connector_code'] === '' || $form['line_id'] === '')) {
            $this->failOpenLineRouteSave('Для рабочего маршрута нужны код соединителя и открытая линия.');

            return false;
        }

        if ($isUsableStatus && $this->hasOpenLineOwnerConflict($profile, $route, $form['line_id'])) {
            $this->failOpenLineRouteSave('Открытая линия уже занята другим рабочим маршрутом.');

            return false;
        }

        return true;
    }

    protected function hasOpenLineOwnerConflict(Bitrix24Profile $profile, ?Bitrix24OpenLineRoute $route, string $lineId): bool
    {
        if ($lineId === '') {
            return false;
        }

        $lineOwnerKey = sprintf('%s#%s', $profile->portal_domain, $lineId);

        return Bitrix24OpenLineRoute::query()
            ->where('line_owner_key', $lineOwnerKey)
            ->when($route instanceof Bitrix24OpenLineRoute, fn ($query) => $query->whereKeyNot($route->id))
            ->exists();
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,source_id:string}  $form
     */
    protected function resolveLineOwnerLabel(
        Bitrix24Profile $profile,
        Channel $channel,
        ?Bitrix24OpenLineRoute $route,
        array $form,
    ): string {
        $status = (string) ($form['status'] ?? '');
        $lineId = trim((string) ($form['line_id'] ?? ''));

        if ($lineId === '' || ! in_array($status, Bitrix24OpenLineRoute::usableStatuses(), true)) {
            return 'Не проверяется для нерабочего маршрута';
        }

        if ($this->hasOpenLineOwnerConflict($profile, $route, $lineId)) {
            return 'Занята другим маршрутом в текущей базе';
        }

        if ($route instanceof Bitrix24OpenLineRoute && $route->isUsable()) {
            return sprintf('Текущий маршрут канала #%d', $channel->id);
        }

        return 'Свободна в текущей базе';
    }

    protected function nullableFormValue(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    protected function nullableIntegerFormValue(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return ctype_digit($trimmed) ? (int) $trimmed : null;
    }

    protected function filledButInvalidInteger(mixed $value): bool
    {
        $trimmed = trim((string) $value);

        return $trimmed !== '' && ! ctype_digit($trimmed);
    }

    protected function failOpenLineRouteSave(string $message): void
    {
        $this->openLineRouteErrorMessage = $message;

        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function failApplicationNameSave(string $message): void
    {
        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function failProfileSettingsSave(string $message): void
    {
        $this->profileSettingsErrorMessage = $message;

        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    /**
     * @return array{visible: bool, enabled: bool, label: string, reason: string}
     */
    protected function resolveOpenLineAutoSetupState(
        Bitrix24Profile $profile,
        Channel $channel,
        ?Bitrix24OpenLineRoute $route,
    ): array {
        $default = [
            'visible' => true,
            'enabled' => false,
            'label' => $route instanceof Bitrix24OpenLineRoute && $route->isUsable()
                ? 'Обновить карточку'
                : 'Настроить ОЛ',
            'reason' => '',
        ];

        if (! $this->canAutoSetupOpenLineRoutes()) {
            return [...$default, 'reason' => 'Доступно только суперадминистратору'];
        }

        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection || $record->status !== Bitrix24Connection::STATUS_ACTIVE) {
            return [...$default, 'reason' => 'Bitrix24-подключение не активно'];
        }

        if ($profile->portal_domain !== AutoSetupBitrix24OpenLineRouteAction::SUPPORTED_PORTAL_DOMAIN) {
            return [...$default, 'reason' => 'Доступно только для stagecrm.fvds.ru'];
        }

        $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);

        if (! in_array($channelType, [
            Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_BOT,
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX,
        ], true)) {
            return [...$default, 'visible' => false, 'reason' => ''];
        }

        if (! $channel->is_active) {
            return [...$default, 'reason' => 'Канал выключен'];
        }

        if (! $channel->hasBotTokenConfigured()) {
            return [...$default, 'reason' => 'Нет токена'];
        }

        $connectorCode = match ($channelType) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => $profile->max_connector_code,
            default => $profile->telegram_connector_code,
        };
        $sourceId = match ($channelType) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => $profile->max_source_id,
            default => $profile->telegram_source_id,
        };
        $channelLabel = match ($channelType) {
            Bitrix24OpenLineRoute::CHANNEL_TYPE_MAX => 'MAX',
            default => 'Telegram',
        };

        if (! filled($connectorCode)) {
            return [...$default, 'reason' => "Не заполнен {$channelLabel} connector_code"];
        }

        if (! filled($sourceId)) {
            return [...$default, 'reason' => "Не заполнен {$channelLabel} source_id"];
        }

        $actualScopes = collect($record->scope ?? [])
            ->map(fn (mixed $scope): string => mb_strtolower(trim((string) $scope)))
            ->filter()
            ->values()
            ->all();

        if (in_array('app', $actualScopes, true)) {
            return [...$default, 'enabled' => true, 'reason' => ''];
        }

        $missingScopes = array_values(array_diff(AutoSetupBitrix24OpenLineRouteAction::REQUIRED_SCOPES, $actualScopes));

        if ($missingScopes !== []) {
            return [...$default, 'reason' => 'Не хватает прав приложения Bitrix24'];
        }

        return [...$default, 'enabled' => true, 'reason' => ''];
    }
}
