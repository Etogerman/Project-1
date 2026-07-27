<?php

namespace App\Filament\Resources\Bitrix24Connections\Pages;

use App\Filament\Resources\Bitrix24Connections\Bitrix24ConnectionResource;
use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Bitrix24SyncLog;
use App\Models\Bitrix24WebhookEvent;
use App\Models\Channel;
use App\Models\Dialog;
use App\Models\User;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24OpenLineAutoSetupException;
use App\Services\Bitrix24\Bitrix24OpenLineRepairException;
use App\Services\Bitrix24\Bitrix24OpenLineRouteOperationLock;
use App\Services\Bitrix24\Bitrix24OpenLinesRouteRegistryException;
use App\Services\Bitrix24\DoctorBitrix24OpenLinesRouteRegistryAction;
use App\Services\Bitrix24\PublishBitrix24OpenLinesRouteRegistryAction;
use App\Services\Bitrix24\RepairStaleBitrix24OpenLineAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ViewBitrix24Connection extends ViewRecord
{
    protected static string $resource = Bitrix24ConnectionResource::class;

    private const STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION = 'openlines_stale_chat_ignored';

    public string $webhookEventCallbackTypeFilter = '';

    public string $webhookEventProcessingStatusFilter = '';

    public string $syncLogStatusFilter = '';

    /**
     * @var array<int, array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}>
     */
    public array $openLineRouteForms = [];

    /**
     * @var array<int, array{owner_key:string,display_name:string,callback_base_url:string,status:string}>
     */
    public array $callbackOwnerForms = [];

    /**
     * @var array{application_name:string}
     */
    public array $applicationNameForm = [
        'application_name' => '',
    ];

    /**
     * @var array<string, string>
     */
    public array $profileSettingsForm = [
        'telegram_source_id' => '',
        'max_source_id' => '',
        'telegram_connector_code' => '',
        'max_connector_code' => '',
        'default_assigned_user_id' => '',
        'default_deal_category_id' => '',
        'default_deal_stage_id' => '',
        'crm_field_name_source' => '',
        'crm_field_age_exact' => '',
        'crm_field_gender' => '',
        'crm_field_age_range' => '',
        'crm_field_contact_id' => '',
        'crm_field_channel_id' => '',
        'crm_field_channel_name' => '',
        'crm_field_platform' => '',
        'crm_field_bot_code' => '',
        'crm_field_bot_name' => '',
        'crm_field_alt_first_name' => '',
        'crm_field_alt_last_name' => '',
        'crm_field_name_conflict' => '',
        'crm_name_source_automatic_id' => '',
        'crm_name_source_self_reported_id' => '',
        'crm_name_source_training_verified_id' => '',
        'crm_gender_male_id' => '',
        'crm_gender_female_id' => '',
        'crm_gender_unknown_id' => '',
    ];

    /**
     * @var array{secret:string}
     */
    public array $openLinesRouteRegistryForm = [
        'secret' => '',
    ];

    public ?string $openLineRouteErrorMessage = null;

    public ?string $openLineRouteSuccessMessage = null;

    public ?string $profileSettingsErrorMessage = null;

    public ?string $callbackOwnersErrorMessage = null;

    public ?string $openLinesRouteRegistryErrorMessage = null;

    public ?string $openLinesRouteRegistrySuccessMessage = null;

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->reloadOpenLineRouteForms();
        $this->reloadApplicationNameForm();
        $this->reloadProfileSettingsForm();
        $this->reloadCallbackOwnerForms();
        $this->reloadOpenLinesRouteRegistryForm();
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
        return null;
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

    /**
     * @return array{label:string,tone:string,summary:string,recommendation:string,details:list<array{label:string,value:string,tone:string}>}
     */
    public function getQueueHealthCard(): array
    {
        $record = $this->getRecord();
        $now = now();
        $staleThresholdSeconds = 30;
        $trackedQueues = collect([
            'default',
            config('bots.auto_reply_queue', 'default'),
            config('bots.scenario_queue', config('bots.auto_reply_queue', 'default')),
            config('bitrix24.openlines.live_export_queue', 'default'),
        ])
            ->map(fn (mixed $queue): string => trim((string) $queue))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $pendingOpenLinesQuery = Bitrix24WebhookEvent::query()
            ->where('callback_type', Bitrix24WebhookEvent::TYPE_OPENLINES)
            ->where('processing_status', Bitrix24WebhookEvent::STATUS_PENDING);

        if ($record instanceof Bitrix24Connection) {
            $pendingOpenLinesQuery->where('connection_id', $record->id);
        }

        $pendingOpenLinesCount = (clone $pendingOpenLinesQuery)->count();
        $oldestPendingOpenLinesAt = (clone $pendingOpenLinesQuery)->oldest('created_at')->value('created_at');
        $oldestPendingOpenLinesAge = $this->ageInSeconds($oldestPendingOpenLinesAt, $now);

        $lastProcessedOpenLinesQuery = Bitrix24WebhookEvent::query()
            ->where('callback_type', Bitrix24WebhookEvent::TYPE_OPENLINES)
            ->where('processing_status', Bitrix24WebhookEvent::STATUS_PROCESSED)
            ->whereNotNull('processed_at');

        if ($record instanceof Bitrix24Connection) {
            $lastProcessedOpenLinesQuery->where('connection_id', $record->id);
        }

        $lastProcessedOpenLinesAt = $lastProcessedOpenLinesQuery->latest('processed_at')->value('processed_at');

        $readyJobsCount = 0;
        $totalTrackedJobsCount = 0;
        $staleReservedJobsCount = 0;
        $failedJobsCount = 0;
        $oldestReadyJobCreatedAt = null;
        $oldestReadyJobAge = null;

        if (Schema::hasTable('jobs')) {
            $readyJobsQuery = DB::table('jobs')
                ->whereIn('queue', $trackedQueues)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', $now->timestamp);

            $readyJobsCount = (clone $readyJobsQuery)->count();
            $oldestReadyJobCreatedAt = (clone $readyJobsQuery)->min('created_at');
            $oldestReadyJobAge = $this->ageInSeconds($oldestReadyJobCreatedAt, $now);

            $staleReservedJobsCount = DB::table('jobs')
                ->whereIn('queue', $trackedQueues)
                ->whereNotNull('reserved_at')
                ->where('reserved_at', '<=', $now->copy()->subMinutes(5)->timestamp)
                ->count();

            $totalTrackedJobsCount = DB::table('jobs')
                ->whereIn('queue', $trackedQueues)
                ->count();
        }

        if (Schema::hasTable('failed_jobs')) {
            $failedJobsCount = DB::table('failed_jobs')
                ->whereIn('queue', $trackedQueues)
                ->count();
        }

        $hasStaleOpenLines = $pendingOpenLinesCount > 0
            && $oldestPendingOpenLinesAge !== null
            && $oldestPendingOpenLinesAge >= $staleThresholdSeconds;
        $hasStaleReadyJobs = $readyJobsCount > 0
            && $oldestReadyJobAge !== null
            && $oldestReadyJobAge >= $staleThresholdSeconds;
        $hasStaleQueue = $hasStaleOpenLines || $hasStaleReadyJobs || $staleReservedJobsCount > 0;

        $tone = match (true) {
            $hasStaleQueue => 'danger',
            $pendingOpenLinesCount > 0 || $readyJobsCount > 0 || $failedJobsCount > 0 => 'warning',
            default => 'success',
        };

        $label = match ($tone) {
            'danger' => 'Очередь не обрабатывается',
            'warning' => 'Есть задачи в очереди',
            default => 'Очередь без задержек',
        };

        $summary = match ($tone) {
            'danger' => 'Callback-и или jobs ждут дольше '.$staleThresholdSeconds.' секунд. Обычно это значит, что локальный queue worker остановлен.',
            'warning' => 'Есть свежие задачи или старые failed jobs. Worker должен забрать свежие задачи в ближайшие секунды.',
            default => 'Ожидающих callback-и/jobs нет. Прямого heartbeat worker-а пока нет, статус подтверждается отсутствием зависших задач.',
        };

        $recommendation = $tone === 'danger'
            ? 'Запустите worker-ы очередей: '.implode(', ', $trackedQueues).'.'
            : '';

        return [
            'label' => $label,
            'tone' => $tone,
            'summary' => $summary,
            'recommendation' => $recommendation,
            'details' => [
                [
                    'label' => 'Драйвер очереди',
                    'value' => (string) config('queue.default', 'database'),
                    'tone' => 'gray',
                ],
                [
                    'label' => 'Callback-и Open Lines',
                    'value' => $this->formatQueueCountWithAge($pendingOpenLinesCount, $oldestPendingOpenLinesAge),
                    'tone' => $hasStaleOpenLines ? 'danger' : ($pendingOpenLinesCount > 0 ? 'warning' : 'success'),
                ],
                [
                    'label' => 'Задачи к обработке',
                    'value' => $this->formatQueueCountWithAge($readyJobsCount, $oldestReadyJobAge),
                    'tone' => $hasStaleReadyJobs ? 'danger' : ($readyJobsCount > 0 ? 'warning' : 'success'),
                ],
                [
                    'label' => 'Всего задач',
                    'value' => (string) $totalTrackedJobsCount,
                    'tone' => $totalTrackedJobsCount > 0 ? 'warning' : 'success',
                ],
                [
                    'label' => 'Зависшие в работе',
                    'value' => (string) $staleReservedJobsCount,
                    'tone' => $staleReservedJobsCount > 0 ? 'danger' : 'success',
                ],
                [
                    'label' => 'Ошибки задач',
                    'value' => (string) $failedJobsCount,
                    'tone' => $failedJobsCount > 0 ? 'warning' : 'success',
                ],
                [
                    'label' => 'Последний Open Lines callback',
                    'value' => $this->formatQueueTimestamp($lastProcessedOpenLinesAt),
                    'tone' => $lastProcessedOpenLinesAt === null ? 'gray' : 'success',
                ],
            ],
        ];
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

    public function canEditCallbackOwners(): bool
    {
        return $this->canEditProfileSettings();
    }

    public function canManageOpenLinesRouteRegistry(): bool
    {
        return $this->canEditProfileSettings();
    }

    /**
     * @return array<string, mixed>
     */
    public function getOpenLinesRouteRegistryCard(): array
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            return [
                'available' => false,
            ];
        }

        $ownerCount = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->count();
        $routeCount = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->whereIn('status', Bitrix24OpenLineRoute::usableStatuses())
            ->whereNotNull('connector_code')
            ->whereNotNull('line_id')
            ->count();
        $lastStatus = (string) ($profile->openlines_route_registry_last_status ?? '');

        return [
            'available' => true,
            'endpoint_url' => $profile->openLinesRouteRegistryEndpointUrl(),
            'secret_configured' => $profile->hasOpenLinesRouteRegistrySecret(),
            'secret_label' => $profile->hasOpenLinesRouteRegistrySecret() ? 'Настроен' : 'Не настроен',
            'secret_tone' => $profile->hasOpenLinesRouteRegistrySecret() ? 'success' : 'danger',
            'last_status' => $lastStatus !== '' ? $lastStatus : 'not_checked',
            'last_status_label' => $this->formatOpenLinesRouteRegistryStatus($lastStatus),
            'last_status_tone' => $this->openLinesRouteRegistryStatusTone($lastStatus),
            'last_error' => filled($profile->openlines_route_registry_last_error)
                ? (string) $profile->openlines_route_registry_last_error
                : 'Ошибок не было',
            'last_checked_at' => $this->formatTimestamp($profile->openlines_route_registry_last_checked_at),
            'last_published_at' => $this->formatTimestamp($profile->openlines_route_registry_last_published_at),
            'owner_count' => $ownerCount,
            'route_count' => $routeCount,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getCallbackOwnerStatusOptions(): array
    {
        return [
            Bitrix24CallbackOwner::STATUS_ACTIVE => 'Активен',
            Bitrix24CallbackOwner::STATUS_INACTIVE => 'Отключен',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCallbackOwnerCards(): array
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            return [];
        }

        return Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->orderByRaw('case when owner_key = ? then 0 else 1 end', [Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY])
            ->orderBy('owner_key')
            ->get()
            ->map(fn (Bitrix24CallbackOwner $owner): array => [
                'id' => $owner->id,
                'label' => $owner->label(),
                'status_label' => $this->getCallbackOwnerStatusOptions()[$owner->status] ?? $owner->status,
                'status_tone' => $owner->isActive() ? 'success' : 'gray',
            ])
            ->values()
            ->all();
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
            ->with(['bitrix24Profile', 'callbackOwner', 'channel'])
            ->where('bitrix24_profile_id', $profile->id)
            ->get()
            ->keyBy('channel_id');

        return $this->getOpenLineRouteChannels()
            ->map(function (Channel $channel) use ($profile, $routes): array {
                /** @var Bitrix24OpenLineRoute|null $route */
                $route = $routes->get($channel->id);
                $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);
                $form = $this->normalizeOpenLineRouteForm(
                    $this->openLineRouteForms[$channel->id] ?? $this->defaultOpenLineRouteForm($profile, $channel, $route),
                );
                $status = (string) ($route?->status ?? '');
                $autoSetup = $this->resolveOpenLineAutoSetupState($profile, $channel, $route);
                $bindingDiagnostics = $this->resolveRouteBindingDiagnostics($route, $form);
                $callbackDiagnostics = $this->resolveLatestOpenLinesCallbackDiagnostics($form);
                $staleCallbackDiagnostics = $this->resolveLatestStaleOpenLinesCallbackDiagnostics($form);

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
                    'line_name' => filled($route?->line_name) ? (string) $route?->line_name : '—',
                    'source_id' => filled($route?->source_id) ? (string) $route?->source_id : '—',
                    'callback_owner_options' => $this->callbackOwnerOptions($profile),
                    'callback_owner_label' => $this->resolveCallbackOwnerLabel($profile, $form),
                    'line_owner_label' => $this->resolveLineOwnerLabel($profile, $channel, $route, $form),
                    'last_error_message' => filled($route?->last_error_message) ? (string) $route?->last_error_message : 'Ошибок не было',
                    'callback_diagnostic_label' => $callbackDiagnostics['label'],
                    'callback_diagnostic_tone' => $callbackDiagnostics['tone'],
                    'stale_callback_visible' => $staleCallbackDiagnostics['visible'],
                    'stale_callback_label' => $staleCallbackDiagnostics['label'],
                    'stale_callback_tone' => $staleCallbackDiagnostics['tone'],
                    'stale_callback_title' => $staleCallbackDiagnostics['title'],
                    'stale_callback_can_repair' => $staleCallbackDiagnostics['can_repair'],
                    'binding_diagnostic_label' => $bindingDiagnostics['label'],
                    'binding_diagnostic_tone' => $bindingDiagnostics['tone'],
                    'binding_diagnostic_can_reset' => $bindingDiagnostics['can_reset'],
                    'auto_setup_visible' => $autoSetup['visible'],
                    'auto_setup_enabled' => $autoSetup['enabled'],
                    'auto_setup_label' => $autoSetup['label'],
                    'auto_setup_reason' => $autoSetup['reason'],
                ];
            })
            ->values()
            ->all();
    }

    public function getBitrixBoxConfigSnippet(): ?string
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            return null;
        }

        $routes = Bitrix24OpenLineRoute::query()
            ->with(['callbackOwner', 'channel'])
            ->where('bitrix24_profile_id', $profile->id)
            ->whereIn('status', Bitrix24OpenLineRoute::usableStatuses())
            ->whereNotNull('connector_code')
            ->whereNotNull('line_id')
            ->orderBy('connector_code')
            ->orderBy('line_id')
            ->get();

        if ($routes->isEmpty()) {
            return null;
        }

        $connectors = [];
        $connectorTypes = [];

        foreach ($routes as $route) {
            $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType((string) $route->channel_type);
            $connectorCode = trim((string) $route->connector_code);
            $lineId = trim((string) $route->line_id);

            if ($connectorType === null || $connectorCode === '' || $lineId === '') {
                continue;
            }

            if (isset($connectorTypes[$connectorCode]) && $connectorTypes[$connectorCode] !== $connectorType) {
                return null;
            }

            $connectorTypes[$connectorCode] = $connectorType;

            if (! isset($connectors[$connectorCode])) {
                $connectors[$connectorCode] = $this->bitrixBoxConnectorEntry($route, $lineId, $connectorType);
            }

            $connectors[$connectorCode]['lines'][$lineId] = [
                'line_name' => $this->bitrixBoxLineName($route),
                'owner_profile_key' => (string) ($route->callbackOwner?->owner_key ?? $profile->profile_key),
                'owner_callback_base_url' => (string) ($route->callbackOwner?->callback_base_url ?? $profile->callback_base_url),
            ];
        }

        if ($connectors === []) {
            return null;
        }

        return "'connectors' => ".var_export($connectors, true).",\n";
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
        $user = auth()->user();

        try {
            $saved = app(Bitrix24OpenLineRouteOperationLock::class)->run(
                $profile->id,
                $channel->id,
                fn (): bool => DB::transaction(function () use ($profile, $channel, $form, $user): bool {
                    $route = Bitrix24OpenLineRoute::query()
                        ->where('bitrix24_profile_id', $profile->id)
                        ->where('channel_id', $channel->id)
                        ->lockForUpdate()
                        ->first();

                    if (! $this->validateStoredOpenLineRouteTransition($route, $form)) {
                        return false;
                    }

                    if (! $this->validateOpenLineRouteForm($profile, $channel, $route, $form)) {
                        return false;
                    }

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
                        'line_name' => $this->nullableFormValue($form['line_name']),
                        'callback_owner_id' => $this->nullableIntegerFormValue($form['callback_owner_id']),
                        'source_id' => $this->nullableFormValue($form['source_id']),
                        'status' => $form['status'],
                        'updated_by_user_id' => $user instanceof User ? $user->id : null,
                    ]);

                    $route->save();

                    return true;
                }, attempts: 3),
            );
        } catch (LockTimeoutException) {
            $this->failOpenLineRouteSave(Bitrix24OpenLineRouteOperationLock::BUSY_MESSAGE);

            return;
        } catch (QueryException $exception) {
            if (! $this->isOpenLineRouteUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $this->failOpenLineRouteSave('Открытая линия уже занята другим рабочим маршрутом.');

            return;
        }

        if (! $saved) {
            return;
        }

        $this->openLineRouteErrorMessage = null;
        $this->openLineRouteSuccessMessage = null;
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Маршрут открытой линии сохранён')
            ->send();
    }

    public function createLocalCallbackOwner(): void
    {
        abort_unless($this->canEditCallbackOwners(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failCallbackOwnerSave('Профиль Bitrix24 не найден.');

            return;
        }

        $callbackBaseUrl = Bitrix24Profile::normalizeCallbackBaseUrl($profile->callback_base_url)
            ?? (string) $profile->callback_base_url;

        if ($callbackBaseUrl === '') {
            $this->failCallbackOwnerSave('В профиле не заполнен callback URL.');

            return;
        }

        if ($this->isCallbackBaseUrlUsedByAnotherProfile($profile, $callbackBaseUrl)) {
            $this->failCallbackOwnerSave('Такой callback URL уже используется другим профилем Bitrix24.');

            return;
        }

        try {
            Bitrix24CallbackOwner::query()->updateOrCreate(
                [
                    'bitrix24_profile_id' => $profile->id,
                    'owner_key' => Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY,
                ],
                [
                    'display_name' => 'Локалка 1',
                    'callback_base_url' => $callbackBaseUrl,
                    'status' => Bitrix24CallbackOwner::STATUS_ACTIVE,
                ],
            );
        } catch (QueryException) {
            $this->failCallbackOwnerSave('Такой callback URL уже занят другим владельцем.');

            return;
        }

        $this->callbackOwnersErrorMessage = null;
        $this->reloadCallbackOwnerForms();
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Callback-владелец local-1 создан')
            ->send();
    }

    public function saveCallbackOwner(int|string $ownerId): void
    {
        abort_unless($this->canEditCallbackOwners(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failCallbackOwnerSave('Профиль Bitrix24 не найден.');

            return;
        }

        $owner = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->whereKey($ownerId)
            ->first();

        if (! $owner instanceof Bitrix24CallbackOwner) {
            $this->failCallbackOwnerSave('Callback-владелец не найден.');

            return;
        }

        $form = $this->callbackOwnerForms[$owner->id] ?? [];
        $ownerKey = trim((string) ($form['owner_key'] ?? ''));
        $displayName = trim((string) ($form['display_name'] ?? ''));
        $callbackBaseUrl = Bitrix24Profile::normalizeCallbackBaseUrl(trim((string) ($form['callback_base_url'] ?? '')));
        $status = trim((string) ($form['status'] ?? Bitrix24CallbackOwner::STATUS_INACTIVE));

        if ($ownerKey === '') {
            $this->failCallbackOwnerSave('Ключ callback-владельца не заполнен.');

            return;
        }

        if (mb_strlen($ownerKey) > 64) {
            $this->failCallbackOwnerSave('Ключ callback-владельца должен быть не длиннее 64 символов.');

            return;
        }

        if ($callbackBaseUrl === null) {
            $this->failCallbackOwnerSave('Callback URL должен быть корректным URL.');

            return;
        }

        if ($this->isCallbackBaseUrlUsedByAnotherProfile($profile, $callbackBaseUrl)) {
            $this->failCallbackOwnerSave('Такой callback URL уже используется другим профилем Bitrix24.');

            return;
        }

        if (! array_key_exists($status, $this->getCallbackOwnerStatusOptions())) {
            $this->failCallbackOwnerSave('Выбран неизвестный статус callback-владельца.');

            return;
        }

        $keyConflict = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('owner_key', $ownerKey)
            ->whereKeyNot($owner->id)
            ->exists();

        if ($keyConflict) {
            $this->failCallbackOwnerSave('Такой ключ callback-владельца уже есть в этом профиле.');

            return;
        }

        try {
            $owner->fill([
                'owner_key' => $ownerKey,
                'display_name' => $this->nullableFormValue($displayName),
                'callback_base_url' => $callbackBaseUrl,
                'status' => $status,
            ])->save();
        } catch (QueryException) {
            $this->failCallbackOwnerSave('Такой callback URL уже занят другим владельцем.');

            return;
        }

        $this->callbackOwnersErrorMessage = null;
        $this->reloadCallbackOwnerForms();
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Callback-владелец сохранён')
            ->send();
    }

    public function saveOpenLinesRouteRegistrySecret(): void
    {
        abort_unless($this->canManageOpenLinesRouteRegistry(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failOpenLinesRouteRegistry('Профиль Bitrix24 не найден.');

            return;
        }

        $secret = trim((string) ($this->openLinesRouteRegistryForm['secret'] ?? ''));

        if ($secret === '') {
            $this->failOpenLinesRouteRegistry('Registry secret не заполнен.');

            return;
        }

        if (mb_strlen($secret) < 32 || mb_strlen($secret) > 256) {
            $this->failOpenLinesRouteRegistry('Registry secret должен быть длиной от 32 до 256 символов.');

            return;
        }

        $profile->forceFill([
            'openlines_route_registry_secret_encrypted' => $secret,
            'openlines_route_registry_last_status' => null,
            'openlines_route_registry_last_error' => null,
        ])->save();

        $this->getRecord()->refresh();
        $this->openLinesRouteRegistryErrorMessage = null;
        $this->openLinesRouteRegistrySuccessMessage = 'Registry secret сохранён. Значение скрыто и повторно не показывается.';
        $this->reloadOpenLinesRouteRegistryForm();

        Notification::make()
            ->success()
            ->title('Registry secret сохранён')
            ->send();
    }

    public function doctorOpenLinesRouteRegistry(): void
    {
        abort_unless($this->canManageOpenLinesRouteRegistry(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failOpenLinesRouteRegistry('Профиль Bitrix24 не найден.');

            return;
        }

        try {
            $result = app(DoctorBitrix24OpenLinesRouteRegistryAction::class)->handle($profile);
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->failOpenLinesRouteRegistry('Doctor завершился ошибкой: '.$exception->errorCode);

            return;
        }

        $this->getRecord()->refresh();
        $this->openLinesRouteRegistryErrorMessage = null;
        $warningCount = (int) ($result['warning_count'] ?? 0);
        $this->openLinesRouteRegistrySuccessMessage = match (true) {
            $result['status'] === Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED && $warningCount === 0 => 'Doctor: Bitrix registry синхронизирован.',
            $result['status'] === Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED => sprintf('Doctor: owner scope синхронизирован, предупреждений: %d.', $warningCount),
            $warningCount > 0 => sprintf('Doctor: найдено отличий: %d, предупреждений: %d.', $result['diff_count'], $warningCount),
            default => sprintf('Doctor: найдено отличий: %d.', $result['diff_count']),
        };

        $notification = Notification::make()
            ->title($this->openLinesRouteRegistrySuccessMessage);

        if ($result['status'] === Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED) {
            $notification->success();
        } else {
            $notification->warning();
        }

        $notification->send();
    }

    public function publishOpenLinesRouteRegistry(): void
    {
        abort_unless($this->canManageOpenLinesRouteRegistry(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failOpenLinesRouteRegistry('Профиль Bitrix24 не найден.');

            return;
        }

        try {
            $result = app(PublishBitrix24OpenLinesRouteRegistryAction::class)->handle($profile);
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->failOpenLinesRouteRegistry('Publish завершился ошибкой: '.$exception->errorCode);

            return;
        }

        $this->getRecord()->refresh();
        $this->openLinesRouteRegistryErrorMessage = null;
        $this->openLinesRouteRegistrySuccessMessage = sprintf(
            'Registry опубликован: owners %d, routes %d.',
            $result['published_owners'],
            $result['published_routes'],
        );

        Notification::make()
            ->success()
            ->title('OpenLines registry опубликован')
            ->body($this->openLinesRouteRegistrySuccessMessage)
            ->send();
    }

    public function resetStaleOpenLineBindings(int|string $channelId): void
    {
        abort_unless($this->canEditOpenLineRoutes(), 403);

        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->failOpenLineRouteSave('Профиль Bitrix24 не найден.');

            return;
        }

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $route instanceof Bitrix24OpenLineRoute) {
            $this->failOpenLineRouteSave('Маршрут открытой линии не найден.');

            return;
        }

        $form = $this->normalizeOpenLineRouteForm($this->openLineRouteForms[$route->channel_id] ?? []);
        $staleDialogIds = $route->dialogs()
            ->select(['id', 'bitrix24_open_line_user_code_override', 'bitrix24_open_line_binding_verified_at'])
            ->whereNotNull('bitrix24_open_line_binding_verified_at')
            ->get()
            ->filter(fn (Dialog $dialog): bool => $this->isDialogBindingStale($dialog, $form))
            ->pluck('id')
            ->all();

        if ($staleDialogIds === []) {
            Notification::make()
                ->success()
                ->title('Устаревших привязок не найдено')
                ->send();

            return;
        }

        Dialog::query()
            ->whereKey($staleDialogIds)
            ->update([
                'bitrix24_open_line_user_code_override' => null,
                'bitrix24_open_line_resolved_chat_id_override' => null,
                'bitrix24_open_line_binding_verified_at' => null,
            ]);

        Notification::make()
            ->success()
            ->title('Устаревшие привязки сброшены')
            ->body(sprintf('Диалогов: %d. Следующий входящий callback заново подтвердит актуальную ОЛ.', count($staleDialogIds)))
            ->send();
    }

    public function repairLatestStaleOpenLine(int|string $channelId): void
    {
        abort_unless($this->canEditOpenLineRoutes(), 403);

        $record = $this->getRecord();
        $profile = $this->getBitrix24Profile();

        if (! $record instanceof Bitrix24Connection || ! $profile instanceof Bitrix24Profile) {
            $this->failOpenLineRouteSave('Подключение или профиль Bitrix24 не найдены.');

            return;
        }

        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channelId)
            ->first();

        if (! $route instanceof Bitrix24OpenLineRoute) {
            $this->failOpenLineRouteSave('Маршрут открытой линии не найден.');

            return;
        }

        $form = $this->normalizeOpenLineRouteForm($this->openLineRouteForms[$route->channel_id] ?? []);
        $staleLog = $this->latestStaleOpenLinesCallbackLogForForm($record, $form);

        if (! $staleLog instanceof Bitrix24SyncLog) {
            $this->failOpenLineRouteSave('Диагностика старой ОЛ для этого маршрута не найдена.');

            return;
        }

        try {
            $result = app(RepairStaleBitrix24OpenLineAction::class)->handle($record, $route, $staleLog);
        } catch (Bitrix24OpenLineRepairException $exception) {
            $this->failOpenLineRouteSave($exception->getMessage());

            Notification::make()
                ->danger()
                ->title('Старую ОЛ не удалось закрыть')
                ->body($exception->getMessage())
                ->send();

            return;
        }

        $this->openLineRouteErrorMessage = null;

        Notification::make()
            ->success()
            ->title('Старая ОЛ закрыта')
            ->body(sprintf(
                'Chat %s закрыт. Локальных привязок сброшено: %d.',
                $result['source_chat_id'],
                $result['reset_dialog_count'],
            ))
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

        if (! $route instanceof Bitrix24OpenLineRoute) {
            $this->failOpenLineRouteSave('Сохранённый маршрут ОЛ не найден. Автоматическое создание новой линии отключено.');

            return;
        }

        try {
            $route = app(AutoSetupBitrix24OpenLineRouteAction::class)->refreshConnectorRegistration($record, $route);
        } catch (Bitrix24OpenLineAutoSetupException $exception) {
            $this->failOpenLineRouteSave($exception->getMessage());

            return;
        }

        $this->openLineRouteErrorMessage = null;
        $this->openLineRouteSuccessMessage = $this->openLineRouteRefreshSuccessMessage($channel, $route);
        $this->reloadOpenLineRouteForms();

        Notification::make()
            ->success()
            ->title('Карточка соединителя обновлена')
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

        foreach ($this->profileIntegerSettingLabels() as $field => $label) {
            if ($this->filledButInvalidInteger($this->profileSettingsForm[$field] ?? '')) {
                $this->failProfileSettingsSave($label.' должен быть числом.');

                return;
            }
        }

        $profile->fill([
            'telegram_source_id' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['telegram_source_id'] ?? ''))),
            'max_source_id' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['max_source_id'] ?? ''))),
            'telegram_connector_code' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['telegram_connector_code'] ?? ''))),
            'max_connector_code' => $this->nullableFormValue(trim((string) ($this->profileSettingsForm['max_connector_code'] ?? ''))),
            'default_assigned_user_id' => $assignedUserId,
            'default_deal_category_id' => $dealCategoryId,
            'default_deal_stage_id' => $dealStageId,
            'crm_field_name_source' => $this->nullableProfileString('crm_field_name_source'),
            'crm_field_age_exact' => $this->nullableProfileString('crm_field_age_exact'),
            'crm_field_gender' => $this->nullableProfileString('crm_field_gender'),
            'crm_field_age_range' => $this->nullableProfileString('crm_field_age_range'),
            'crm_field_contact_id' => $this->nullableProfileString('crm_field_contact_id'),
            'crm_field_channel_id' => $this->nullableProfileString('crm_field_channel_id'),
            'crm_field_channel_name' => $this->nullableProfileString('crm_field_channel_name'),
            'crm_field_platform' => $this->nullableProfileString('crm_field_platform'),
            'crm_field_bot_code' => $this->nullableProfileString('crm_field_bot_code'),
            'crm_field_bot_name' => $this->nullableProfileString('crm_field_bot_name'),
            'crm_field_alt_first_name' => $this->nullableProfileString('crm_field_alt_first_name'),
            'crm_field_alt_last_name' => $this->nullableProfileString('crm_field_alt_last_name'),
            'crm_field_name_conflict' => $this->nullableProfileString('crm_field_name_conflict'),
            'crm_name_source_automatic_id' => $this->nullableIntegerProfileValue('crm_name_source_automatic_id'),
            'crm_name_source_self_reported_id' => $this->nullableIntegerProfileValue('crm_name_source_self_reported_id'),
            'crm_name_source_training_verified_id' => $this->nullableIntegerProfileValue('crm_name_source_training_verified_id'),
            'crm_gender_male_id' => $this->nullableIntegerProfileValue('crm_gender_male_id'),
            'crm_gender_female_id' => $this->nullableIntegerProfileValue('crm_gender_female_id'),
            'crm_gender_unknown_id' => $this->nullableIntegerProfileValue('crm_gender_unknown_id'),
        ]);
        $profile->save();

        $this->profileSettingsErrorMessage = null;
        $this->getRecord()->refresh();
        $this->reloadProfileSettingsForm();
        $this->reloadCallbackOwnerForms();
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

    public function reloadCallbackOwnerForms(): void
    {
        $profile = $this->getBitrix24Profile();

        if (! $profile instanceof Bitrix24Profile) {
            $this->callbackOwnerForms = [];

            return;
        }

        $this->callbackOwnerForms = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->orderByRaw('case when owner_key = ? then 0 else 1 end', [Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY])
            ->orderBy('owner_key')
            ->get()
            ->mapWithKeys(fn (Bitrix24CallbackOwner $owner): array => [
                $owner->id => [
                    'owner_key' => (string) $owner->owner_key,
                    'display_name' => (string) ($owner->display_name ?? ''),
                    'callback_base_url' => (string) $owner->callback_base_url,
                    'status' => (string) $owner->status,
                ],
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
            'crm_field_name_source' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_name_source ?? '') : '',
            'crm_field_age_exact' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_age_exact ?? '') : '',
            'crm_field_gender' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_gender ?? '') : '',
            'crm_field_age_range' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_age_range ?? '') : '',
            'crm_field_contact_id' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_contact_id ?? '') : '',
            'crm_field_channel_id' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_channel_id ?? '') : '',
            'crm_field_channel_name' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_channel_name ?? '') : '',
            'crm_field_platform' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_platform ?? '') : '',
            'crm_field_bot_code' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_bot_code ?? '') : '',
            'crm_field_bot_name' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_bot_name ?? '') : '',
            'crm_field_alt_first_name' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_alt_first_name ?? '') : '',
            'crm_field_alt_last_name' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_alt_last_name ?? '') : '',
            'crm_field_name_conflict' => $profile instanceof Bitrix24Profile ? (string) ($profile->crm_field_name_conflict ?? '') : '',
            'crm_name_source_automatic_id' => $profile instanceof Bitrix24Profile && $profile->crm_name_source_automatic_id !== null ? (string) $profile->crm_name_source_automatic_id : '',
            'crm_name_source_self_reported_id' => $profile instanceof Bitrix24Profile && $profile->crm_name_source_self_reported_id !== null ? (string) $profile->crm_name_source_self_reported_id : '',
            'crm_name_source_training_verified_id' => $profile instanceof Bitrix24Profile && $profile->crm_name_source_training_verified_id !== null ? (string) $profile->crm_name_source_training_verified_id : '',
            'crm_gender_male_id' => $profile instanceof Bitrix24Profile && $profile->crm_gender_male_id !== null ? (string) $profile->crm_gender_male_id : '',
            'crm_gender_female_id' => $profile instanceof Bitrix24Profile && $profile->crm_gender_female_id !== null ? (string) $profile->crm_gender_female_id : '',
            'crm_gender_unknown_id' => $profile instanceof Bitrix24Profile && $profile->crm_gender_unknown_id !== null ? (string) $profile->crm_gender_unknown_id : '',
        ];
    }

    public function reloadOpenLinesRouteRegistryForm(): void
    {
        $this->openLinesRouteRegistryForm = [
            'secret' => '',
        ];
    }

    protected function formatTimestamp(mixed $value): string
    {
        if (! $value instanceof \DateTimeInterface) {
            return '—';
        }

        return $value->format('d.m.Y H:i:s');
    }

    protected function formatOpenLinesRouteRegistryStatus(string $status): string
    {
        return match ($status) {
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED => 'Синхронизирован',
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF => 'Есть отличия',
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED => 'Ошибка',
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_PUBLISHED => 'Опубликован',
            default => 'Не проверялся',
        };
    }

    protected function openLinesRouteRegistryStatusTone(string $status): string
    {
        return match ($status) {
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_SYNCED,
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_PUBLISHED => 'success',
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_DIFF => 'warning',
            Bitrix24Profile::ROUTE_REGISTRY_STATUS_FAILED => 'danger',
            default => 'gray',
        };
    }

    protected function formatQueueCountWithAge(int $count, ?int $ageSeconds): string
    {
        if ($count <= 0) {
            return '0';
        }

        if ($ageSeconds === null) {
            return (string) $count;
        }

        return $count.' · '.$this->formatQueueDuration($ageSeconds);
    }

    protected function formatQueueTimestamp(mixed $value): string
    {
        $date = $this->normalizeQueueTimestamp($value);

        if (! $date instanceof Carbon) {
            return 'Не было';
        }

        return $date->timezone(config('app.timezone', 'Europe/Moscow'))->format('d.m.Y H:i:s');
    }

    protected function ageInSeconds(mixed $value, Carbon $now): ?int
    {
        $date = $this->normalizeQueueTimestamp($value);

        if (! $date instanceof Carbon) {
            return null;
        }

        return max(0, $now->getTimestamp() - $date->getTimestamp());
    }

    protected function normalizeQueueTimestamp(mixed $value): ?Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        if (is_numeric($value)) {
            return Carbon::createFromTimestamp((int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return null;
    }

    protected function formatQueueDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds.' сек';
        }

        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes.' мин '.($seconds % 60).' сек';
        }

        $hours = intdiv($minutes, 60);

        return $hours.' ч '.($minutes % 60).' мин';
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
     * @return array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}
     */
    protected function defaultOpenLineRouteForm(Bitrix24Profile $profile, Channel $channel, ?Bitrix24OpenLineRoute $route): array
    {
        return [
            'status' => (string) ($route?->status ?? $this->defaultStatusForChannel($channel)),
            'connector_code' => (string) ($route?->connector_code ?? $this->defaultConnectorCodeForChannel($profile, $channel)),
            'line_id' => (string) ($route?->line_id ?? ''),
            'line_name' => (string) ($route?->line_name ?? $this->defaultLineNameForChannel($channel)),
            'callback_owner_id' => $route?->callback_owner_id !== null
                ? (string) $route->callback_owner_id
                : $this->defaultCallbackOwnerId($profile),
            'source_id' => (string) ($route?->source_id ?? $this->defaultSourceIdForChannel($profile, $channel)),
        ];
    }

    protected function defaultStatusForChannel(Channel $channel): string
    {
        $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);

        return Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType($channelType) === null
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

    protected function defaultCallbackOwnerId(Bitrix24Profile $profile): string
    {
        $owner = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('status', Bitrix24CallbackOwner::STATUS_ACTIVE)
            ->orderByRaw('case when owner_key = ? then 0 else 1 end', [Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY])
            ->orderBy('owner_key')
            ->first();

        return $owner instanceof Bitrix24CallbackOwner ? (string) $owner->id : '';
    }

    /**
     * @return array<int, string>
     */
    protected function callbackOwnerOptions(Bitrix24Profile $profile): array
    {
        return Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->orderByRaw('case when owner_key = ? then 0 else 1 end', [Bitrix24CallbackOwner::DEFAULT_LOCAL_OWNER_KEY])
            ->orderBy('owner_key')
            ->get()
            ->mapWithKeys(function (Bitrix24CallbackOwner $owner): array {
                $label = $owner->label();

                if (! $owner->isActive()) {
                    $label .= ' · отключен';
                }

                return [$owner->id => $label];
            })
            ->all();
    }

    protected function resolveActiveCallbackOwner(Bitrix24Profile $profile, string $ownerId): ?Bitrix24CallbackOwner
    {
        $id = $this->nullableIntegerFormValue($ownerId);

        if ($id === null) {
            return null;
        }

        return Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('status', Bitrix24CallbackOwner::STATUS_ACTIVE)
            ->whereKey($id)
            ->first();
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     */
    protected function resolveCallbackOwnerLabel(Bitrix24Profile $profile, array $form): string
    {
        $id = $this->nullableIntegerFormValue($form['callback_owner_id'] ?? '');

        if ($id === null) {
            return 'Не выбран';
        }

        $owner = Bitrix24CallbackOwner::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->whereKey($id)
            ->first();

        if (! $owner instanceof Bitrix24CallbackOwner) {
            return 'Не найден';
        }

        return $owner->isActive() ? $owner->label() : $owner->label().' · отключен';
    }

    protected function defaultLineNameForChannel(Channel $channel): string
    {
        $name = trim((string) $channel->name);

        return $name !== '' ? $name : 'Channel #'.$channel->id;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}
     */
    protected function normalizeOpenLineRouteForm(array $form): array
    {
        return [
            'status' => trim((string) ($form['status'] ?? Bitrix24OpenLineRoute::STATUS_INACTIVE)),
            'connector_code' => trim((string) ($form['connector_code'] ?? '')),
            'line_id' => trim((string) ($form['line_id'] ?? '')),
            'line_name' => trim((string) ($form['line_name'] ?? '')),
            'callback_owner_id' => trim((string) ($form['callback_owner_id'] ?? '')),
            'source_id' => trim((string) ($form['source_id'] ?? '')),
        ];
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
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
        $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);

        if (
            $isUsableStatus
            && Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType($channelType) === null
        ) {
            $this->failOpenLineRouteSave(
                $channelType === Bitrix24OpenLineRoute::CHANNEL_TYPE_TELEGRAM_ACCOUNT
                    ? 'Telegram account пока нельзя сделать рабочим маршрутом открытых линий.'
                    : 'Этот тип канала пока нельзя сделать рабочим маршрутом открытых линий.',
            );

            return false;
        }

        if ($isUsableStatus && ($form['connector_code'] === '' || $form['line_id'] === '')) {
            $this->failOpenLineRouteSave('Для рабочего маршрута нужны код соединителя и открытая линия.');

            return false;
        }

        if ($isUsableStatus && ! ($this->resolveActiveCallbackOwner($profile, $form['callback_owner_id']) instanceof Bitrix24CallbackOwner)) {
            $this->failOpenLineRouteSave('Для рабочего маршрута нужен активный callback-владелец.');

            return false;
        }

        if ($isUsableStatus && $this->hasOpenLineOwnerConflict($profile, $route, $form['line_id'])) {
            $this->failOpenLineRouteSave('Открытая линия уже занята другим рабочим маршрутом.');

            return false;
        }

        return true;
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     */
    protected function validateStoredOpenLineRouteTransition(
        ?Bitrix24OpenLineRoute $route,
        array $form,
    ): bool {
        if (! $route instanceof Bitrix24OpenLineRoute) {
            return true;
        }

        foreach (['connector_code', 'line_id'] as $field) {
            if (trim((string) $route->{$field}) !== $form[$field]) {
                $this->failOpenLineRouteSave(
                    'Обычное сохранение не меняет код соединителя и LINE_ID существующего маршрута.',
                );

                return false;
            }
        }

        if (
            $route->status === Bitrix24OpenLineRoute::STATUS_MISCONFIGURED
            && $form['status'] !== Bitrix24OpenLineRoute::STATUS_MISCONFIGURED
        ) {
            $this->failOpenLineRouteSave(
                'Статус маршрута с ошибкой нельзя менять обычным сохранением.',
            );

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

    protected function isOpenLineRouteUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $message = mb_strtolower($exception->getMessage());

        return in_array($sqlState, ['23000', '23505'], true)
            && str_contains($message, 'bitrix24_open_line_routes')
            && ($sqlState === '23505' || str_contains($message, 'unique'));
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
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

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     * @return array{label:string,tone:string,can_reset:bool}
     */
    protected function resolveRouteBindingDiagnostics(?Bitrix24OpenLineRoute $route, array $form): array
    {
        if (! $route instanceof Bitrix24OpenLineRoute) {
            return [
                'label' => 'Маршрут не сохранён',
                'tone' => 'gray',
                'can_reset' => false,
            ];
        }

        $dialogs = $route->dialogs()
            ->select(['id', 'bitrix24_open_line_user_code_override', 'bitrix24_open_line_binding_verified_at'])
            ->whereNotNull('bitrix24_open_line_binding_verified_at')
            ->get();

        if ($dialogs->isEmpty()) {
            return [
                'label' => 'Нет проверенных',
                'tone' => 'gray',
                'can_reset' => false,
            ];
        }

        $staleCount = $dialogs
            ->filter(fn (Dialog $dialog): bool => $this->isDialogBindingStale($dialog, $form))
            ->count();

        if ($staleCount > 0) {
            return [
                'label' => 'Устаревших: '.$staleCount,
                'tone' => 'danger',
                'can_reset' => true,
            ];
        }

        return [
            'label' => 'Актуальна',
            'tone' => 'success',
            'can_reset' => false,
        ];
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     * @return array{label:string,tone:string}
     */
    protected function resolveLatestOpenLinesCallbackDiagnostics(array $form): array
    {
        $connectorCode = trim((string) ($form['connector_code'] ?? ''));
        $lineId = trim((string) ($form['line_id'] ?? ''));

        if ($connectorCode === '' || $lineId === '') {
            return [
                'label' => 'Нет LINE_ID',
                'tone' => 'gray',
            ];
        }

        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection) {
            return [
                'label' => 'Нет подключения',
                'tone' => 'gray',
            ];
        }

        $event = $this->resolveLatestOpenLinesWebhookEvent($record, $connectorCode, $lineId);

        if (! $event instanceof Bitrix24WebhookEvent) {
            return [
                'label' => 'Нет для LINE '.$lineId,
                'tone' => 'warning',
            ];
        }

        return [
            'label' => 'Был '.$this->formatTimestamp($event->created_at),
            'tone' => 'success',
        ];
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     * @return array{visible:bool,label:string,tone:string,title:string,can_repair:bool}
     */
    protected function resolveLatestStaleOpenLinesCallbackDiagnostics(array $form): array
    {
        $empty = [
            'visible' => false,
            'label' => '',
            'tone' => 'gray',
            'title' => '',
            'can_repair' => false,
        ];
        $connectorCode = trim((string) ($form['connector_code'] ?? ''));
        $lineId = trim((string) ($form['line_id'] ?? ''));

        if ($connectorCode === '' || $lineId === '') {
            return $empty;
        }

        $record = $this->getRecord();

        if (! $record instanceof Bitrix24Connection) {
            return $empty;
        }

        $log = $this->latestStaleOpenLinesCallbackLogForForm($record, $form);

        if (! $log instanceof Bitrix24SyncLog) {
            return $empty;
        }

        $payload = is_array($log->request_payload) ? $log->request_payload : [];
        $sourceChatId = trim((string) data_get($payload, 'source_bitrix_chat_id', ''));
        $currentChatId = trim((string) data_get($payload, 'current_bitrix_chat_id', ''));
        $dialogId = trim((string) data_get($payload, 'dialog_id', ''));
        $bitrixMessageId = trim((string) data_get($payload, 'bitrix_message_id', ''));

        $label = $sourceChatId !== '' && $currentChatId !== ''
            ? sprintf('chat %s -> %s', $sourceChatId, $currentChatId)
            : 'Ответ из старой ОЛ';
        $titleParts = ['Ответ из старой ОЛ проигнорирован.'];
        $repairLog = $this->latestStaleOpenLineRepairLog($record, $log);

        if ($sourceChatId !== '') {
            $titleParts[] = 'Источник: chat '.$sourceChatId.'.';
        }

        if ($currentChatId !== '') {
            $titleParts[] = 'Текущая ОЛ: chat '.$currentChatId.'.';
        }

        if ($dialogId !== '') {
            $titleParts[] = 'Диалог #'.$dialogId.'.';
        }

        if ($bitrixMessageId !== '') {
            $titleParts[] = 'Сообщение Bitrix #'.$bitrixMessageId.'.';
        }

        $titleParts[] = 'Защита не отправила сообщение в канал, чтобы не доставлять ответ из неактуального чата.';

        if ($repairLog instanceof Bitrix24SyncLog) {
            return [
                'visible' => true,
                'label' => $sourceChatId !== '' ? 'chat '.$sourceChatId.' закрыта' : 'Старая ОЛ закрыта',
                'tone' => 'success',
                'title' => implode(' ', array_merge($titleParts, ['Ремонт выполнен '.$this->formatTimestamp($repairLog->created_at).'.'])),
                'can_repair' => false,
            ];
        }

        return [
            'visible' => true,
            'label' => $label,
            'tone' => 'danger',
            'title' => implode(' ', $titleParts),
            'can_repair' => $sourceChatId !== '',
        ];
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     */
    protected function latestStaleOpenLinesCallbackLogForForm(
        Bitrix24Connection $record,
        array $form,
    ): ?Bitrix24SyncLog {
        $connectorCode = trim((string) ($form['connector_code'] ?? ''));
        $lineId = trim((string) ($form['line_id'] ?? ''));

        if ($connectorCode === '' || $lineId === '') {
            return null;
        }

        return $record->syncLogs()
            ->where('operation', self::STALE_OPEN_LINE_MESSAGE_IGNORED_OPERATION)
            ->orderByDesc('id')
            ->limit(120)
            ->get()
            ->first(function (Bitrix24SyncLog $log) use ($connectorCode, $lineId): bool {
                $payload = is_array($log->request_payload) ? $log->request_payload : [];

                return trim((string) data_get($payload, 'connector_code', '')) === $connectorCode
                    && trim((string) data_get($payload, 'line_id', '')) === $lineId;
            });
    }

    protected function latestStaleOpenLineRepairLog(Bitrix24Connection $record, Bitrix24SyncLog $staleLog): ?Bitrix24SyncLog
    {
        return $record->syncLogs()
            ->where('operation', RepairStaleBitrix24OpenLineAction::COMPLETED_OPERATION)
            ->where('status', Bitrix24SyncLog::STATUS_SUCCESS)
            ->where('id', '>', $staleLog->id)
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->first(function (Bitrix24SyncLog $log) use ($staleLog): bool {
                $payload = is_array($log->request_payload) ? $log->request_payload : [];

                return (int) data_get($payload, 'stale_log_id') === (int) $staleLog->id;
            });
    }

    protected function resolveLatestOpenLinesWebhookEvent(
        Bitrix24Connection $record,
        string $connectorCode,
        string $lineId,
    ): ?Bitrix24WebhookEvent {
        return $record->webhookEvents()
            ->where('callback_type', Bitrix24WebhookEvent::TYPE_OPENLINES)
            ->orderByDesc('id')
            ->limit(80)
            ->get()
            ->first(function (Bitrix24WebhookEvent $event) use ($connectorCode, $lineId): bool {
                $payload = is_array($event->payload) ? $event->payload : [];

                return (string) data_get($payload, 'data.CONNECTOR') === $connectorCode
                    && (string) data_get($payload, 'data.LINE') === $lineId;
            });
    }

    /**
     * @param  array{status:string,connector_code:string,line_id:string,line_name:string,callback_owner_id:string,source_id:string}  $form
     */
    protected function isDialogBindingStale(Dialog $dialog, array $form): bool
    {
        $connectorCode = trim((string) ($form['connector_code'] ?? ''));
        $lineId = trim((string) ($form['line_id'] ?? ''));

        if ($connectorCode === '' || $lineId === '') {
            return false;
        }

        $parsed = $this->parseOpenLineUserCode($dialog->bitrix24_open_line_user_code_override);

        if ($parsed === null) {
            return false;
        }

        return $parsed['connector_code'] !== $connectorCode || $parsed['line_id'] !== $lineId;
    }

    /**
     * @return array{connector_code:string,line_id:string}|null
     */
    protected function parseOpenLineUserCode(mixed $value): ?array
    {
        if (! is_scalar($value)) {
            return null;
        }

        $parts = array_values(array_filter(
            explode('|', trim((string) $value)),
            static fn (string $part): bool => $part !== '',
        ));

        if (($parts[0] ?? null) === 'imol') {
            array_shift($parts);
        }

        if (count($parts) < 4) {
            return null;
        }

        return [
            'connector_code' => (string) $parts[0],
            'line_id' => (string) $parts[1],
        ];
    }

    protected function nullableFormValue(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    protected function nullableProfileString(string $field): ?string
    {
        return $this->nullableFormValue(trim((string) ($this->profileSettingsForm[$field] ?? '')));
    }

    protected function nullableIntegerProfileValue(string $field): ?int
    {
        return $this->nullableIntegerFormValue((string) ($this->profileSettingsForm[$field] ?? ''));
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

    /**
     * @return array<string, string>
     */
    protected function profileIntegerSettingLabels(): array
    {
        return [
            'crm_name_source_automatic_id' => 'Name source automatic ID',
            'crm_name_source_self_reported_id' => 'Name source self reported ID',
            'crm_name_source_training_verified_id' => 'Name source training verified ID',
            'crm_gender_male_id' => 'Gender male ID',
            'crm_gender_female_id' => 'Gender female ID',
            'crm_gender_unknown_id' => 'Gender unknown ID',
        ];
    }

    protected function failOpenLineRouteSave(string $message): void
    {
        $this->openLineRouteErrorMessage = $message;
        $this->openLineRouteSuccessMessage = null;

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

    protected function openLineRouteRefreshSuccessMessage(
        Channel $channel,
        Bitrix24OpenLineRoute $route,
    ): string {
        $channelTitle = sprintf('#%d %s', $channel->id, $channel->name);
        $lineSuffix = filled($route->line_id) ? sprintf(', LINE_ID %s', $route->line_id) : '';

        return sprintf('Карточка соединителя обновлена: %s%s.', $channelTitle, $lineSuffix);
    }

    protected function failProfileSettingsSave(string $message): void
    {
        $this->profileSettingsErrorMessage = $message;

        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function failCallbackOwnerSave(string $message): void
    {
        $this->callbackOwnersErrorMessage = $message;

        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function failOpenLinesRouteRegistry(string $message): void
    {
        $this->openLinesRouteRegistryErrorMessage = $message;

        Notification::make()
            ->danger()
            ->title($message)
            ->send();
    }

    protected function isCallbackBaseUrlUsedByAnotherProfile(Bitrix24Profile $profile, string $callbackBaseUrl): bool
    {
        return Bitrix24Profile::query()
            ->where('callback_base_url', $callbackBaseUrl)
            ->whereKeyNot($profile->id)
            ->exists();
    }

    protected function bitrixBoxLineName(Bitrix24OpenLineRoute $route): string
    {
        $routeLineName = trim((string) ($route->line_name ?? ''));

        if ($routeLineName !== '') {
            return $routeLineName;
        }

        $channelName = trim((string) ($route->channel?->name ?? ''));

        if ($channelName === '') {
            return 'Channel #'.$route->channel_id;
        }

        return $channelName;
    }

    /**
     * @return array{name: string, component: string, line_id: string, line_name: string, lines: array<string, mixed>, color: string, label: string}
     */
    protected function bitrixBoxConnectorEntry(Bitrix24OpenLineRoute $route, string $lineId, string $connectorType): array
    {
        return [
            'name' => $this->bitrixBoxConnectorName($connectorType),
            'component' => $this->bitrixBoxConnectorComponent($connectorType),
            'line_id' => $lineId,
            'line_name' => $this->bitrixBoxLineName($route),
            'lines' => [],
            'color' => $this->bitrixBoxConnectorColor($connectorType),
            'label' => $this->bitrixBoxConnectorLabel($connectorType),
        ];
    }

    protected function bitrixBoxConnectorName(string $connectorType): string
    {
        $platformName = match ($connectorType) {
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM => 'Telegram',
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX => 'MAX',
            default => throw new \LogicException('Unsupported Open Lines connector type.'),
        };
        $record = $this->getRecord();
        $connectionName = $record instanceof Bitrix24Connection ? trim((string) $record->application_name) : '';
        $configuredName = trim((string) config('bitrix24.application.name', ''));
        $prefix = $connectionName !== '' && mb_strtolower($connectionName) !== 'abrikosoff connector'
            ? $connectionName
            : ($configuredName !== '' && mb_strtolower($configuredName) !== 'abrikosoff connector' ? $configuredName : 'ABC');

        return mb_substr($prefix.' '.$platformName, 0, 120);
    }

    protected function bitrixBoxConnectorComponent(string $connectorType): string
    {
        return match ($connectorType) {
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM => 'abrikosoff:imconnector.telegram',
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX => 'abrikosoff:imconnector.max',
            default => throw new \LogicException('Unsupported Open Lines connector type.'),
        };
    }

    protected function bitrixBoxConnectorColor(string $connectorType): string
    {
        return match ($connectorType) {
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM => '#27A7E7',
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX => '#7B4DFF',
            default => throw new \LogicException('Unsupported Open Lines connector type.'),
        };
    }

    protected function bitrixBoxConnectorLabel(string $connectorType): string
    {
        return match ($connectorType) {
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_TELEGRAM => 'TG',
            Bitrix24OpenLineRoute::OPEN_LINES_CONNECTOR_TYPE_MAX => 'MX',
            default => throw new \LogicException('Unsupported Open Lines connector type.'),
        };
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
            'label' => 'Обновить карточку',
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

        if (! $route instanceof Bitrix24OpenLineRoute) {
            return [...$default, 'reason' => 'Маршрут ОЛ ещё не сохранён'];
        }

        if (! in_array($route->status, [
            Bitrix24OpenLineRoute::STATUS_ACTIVE,
            Bitrix24OpenLineRoute::STATUS_LEGACY,
            Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
        ], true)) {
            return [...$default, 'reason' => 'Маршрут ОЛ не готов к обновлению'];
        }

        if (! $channel->is_active) {
            return [...$default, 'reason' => 'Канал выключен'];
        }

        if (! $channel->hasBotTokenConfigured()) {
            return [...$default, 'reason' => 'Нет токена'];
        }

        if (! filled($route->connector_code)) {
            return [...$default, 'reason' => 'В маршруте ОЛ не заполнен код соединителя'];
        }

        if (! filled($route->line_id)) {
            return [...$default, 'reason' => 'В маршруте ОЛ не заполнена открытая линия'];
        }

        if (! filled($route->source_id)) {
            return [...$default, 'reason' => 'В маршруте ОЛ не заполнен CRM source'];
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
