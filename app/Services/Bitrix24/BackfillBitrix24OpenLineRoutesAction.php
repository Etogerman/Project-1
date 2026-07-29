<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24CallbackOwner;
use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\Dialog;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;

class BackfillBitrix24OpenLineRoutesAction
{
    public function __construct(
        private readonly Bitrix24OpenLineRouteOperationLock $routeOperationLock,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
    ) {}

    /**
     * @return array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}
     */
    public function handle(): array
    {
        $result = [
            'routes_created' => 0,
            'routes_updated' => 0,
            'dialogs_pinned' => 0,
            'skipped' => 0,
            'warnings' => [],
        ];

        Bitrix24Profile::query()
            ->where('profile_type', Bitrix24Profile::TYPE_FULL_LIVE)
            ->orderBy('id')
            ->chunkById(50, function (Collection $profiles) use (&$result): void {
                foreach ($profiles as $profile) {
                    if (! $profile instanceof Bitrix24Profile) {
                        continue;
                    }

                    $this->backfillProfile($profile, $result);
                }
            });

        return $result;
    }

    /**
     * @param  array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}  $result
     */
    private function backfillProfile(Bitrix24Profile $profile, array &$result): void
    {
        $this->backfillPlatform(
            profile: $profile,
            platform: Channel::PLATFORM_TELEGRAM,
            connectorCode: $this->nullableString($profile->telegram_connector_code),
            lineId: $this->nullableString($profile->telegram_line_id),
            sourceId: $this->nullableString($profile->telegram_source_id),
            result: $result,
        );

        $this->backfillPlatform(
            profile: $profile,
            platform: Channel::PLATFORM_MAX,
            connectorCode: $this->nullableString($profile->max_connector_code),
            lineId: $this->nullableString($profile->max_line_id),
            sourceId: $this->nullableString($profile->max_source_id),
            result: $result,
        );
    }

    /**
     * @param  array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}  $result
     */
    private function backfillPlatform(
        Bitrix24Profile $profile,
        string $platform,
        ?string $connectorCode,
        ?string $lineId,
        ?string $sourceId,
        array &$result,
    ): void {
        if ($connectorCode === null && $lineId === null) {
            return;
        }

        if ($connectorCode === null || $lineId === null) {
            $this->recordSkip($result, sprintf(
                'Профиль `%s`: старая настройка ОЛ для `%s` неполная, connector или line не заполнены.',
                $profile->profile_key,
                $platform,
            ));

            return;
        }

        if (! Bitrix24OpenLineRoute::isValidConnectorCode($connectorCode)) {
            $this->recordSkip($result, sprintf(
                'Профиль `%s`: connector `%s` не соответствует допустимому формату.',
                $profile->profile_key,
                $connectorCode,
            ));

            return;
        }

        $canonicalLineId = Bitrix24OpenLineRoute::canonicalLineId($lineId);

        if ($canonicalLineId === null) {
            $this->recordSkip($result, sprintf(
                'Профиль `%s`: LINE_ID должен состоять из 1–64 цифр, значение `%s` не применено.',
                $profile->profile_key,
                $lineId,
            ));

            return;
        }

        $channel = $this->resolveLegacyChannel(
            $profile,
            $platform,
            $connectorCode,
            $canonicalLineId,
            $result,
        );

        if (! $channel instanceof Channel) {
            return;
        }

        try {
            $outcome = $this->routeOperationLock->run(
                (int) $profile->getKey(),
                (int) $channel->getKey(),
                fn (): array => $this->pinDialogsForExistingRoute(
                    $profile,
                    $channel,
                    $connectorCode,
                    $canonicalLineId,
                    $sourceId,
                ),
            );
        } catch (LockTimeoutException $exception) {
            $this->recordSkip($result, $exception->getMessage());

            return;
        } catch (Bitrix24OpenLinesRouteRegistryException $exception) {
            $this->recordSkip($result, sprintf(
                'Профиль `%s`, `%s:%s`: registry отклонил backfill (%s).',
                $profile->profile_key,
                $connectorCode,
                $canonicalLineId,
                $exception->errorCode,
            ));

            return;
        } catch (Bitrix24OpenLineMutationAuthorityException $exception) {
            $this->recordSkip($result, sprintf(
                'Профиль `%s`, `%s:%s`: mutation fence отклонил backfill (%s).',
                $profile->profile_key,
                $connectorCode,
                $canonicalLineId,
                $exception->errorCode,
            ));

            return;
        }

        if (! $outcome['successful']) {
            $this->recordSkip($result, $outcome['warning']);

            return;
        }

        if ($outcome['route_updated']) {
            $result['routes_updated']++;
        }

        $result['dialogs_pinned'] += $outcome['dialogs_pinned'];
    }

    /**
     * @param  array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}  $result
     */
    private function resolveLegacyChannel(
        Bitrix24Profile $profile,
        string $platform,
        string $connectorCode,
        string $lineId,
        array &$result,
    ): ?Channel {
        $existingRoute = Bitrix24OpenLineRoute::query()
            ->with('channel')
            ->where('bitrix24_profile_id', $profile->id)
            ->where('connector_code', $connectorCode)
            ->where('line_id', $lineId)
            ->usable()
            ->first();

        if ($existingRoute instanceof Bitrix24OpenLineRoute
            && $existingRoute->channel instanceof Channel
            && $existingRoute->channel->platform === $platform
            && $existingRoute->channel->isBotConnection()
        ) {
            return $existingRoute->channel;
        }

        /** @var Collection<int, Channel> $channels */
        $channels = Channel::query()
            ->withCount([
                'dialogs as bitrix24_live_dialogs_count' => fn ($query) => $query
                    ->whereNotNull('bitrix24_live_chat_id')
                    ->where('bitrix24_live_chat_id', '!=', ''),
            ])
            ->where('platform', $platform)
            ->where('connection_type', Channel::CONNECTION_TYPE_BOT)
            ->orderByDesc('bitrix24_live_dialogs_count')
            ->orderBy('id')
            ->get();

        if ($channels->isEmpty()) {
            $this->recordSkip($result, sprintf(
                'Для профиля `%s` не найден локальный канал платформы `%s` под старую ОЛ `%s:%s`.',
                $profile->profile_key,
                $platform,
                $connectorCode,
                $lineId,
            ));

            return null;
        }

        $liveChannels = $channels->filter(
            fn (Channel $channel): bool => (int) ($channel->bitrix24_live_dialogs_count ?? 0) > 0,
        );

        if ($liveChannels->count() === 1) {
            return $liveChannels->first();
        }

        if ($channels->count() === 1) {
            return $channels->first();
        }

        $this->recordSkip($result, sprintf(
            'Профиль `%s`: старую ОЛ `%s:%s` нельзя безопасно привязать автоматически, найдено несколько каналов `%s`.',
            $profile->profile_key,
            $connectorCode,
            $lineId,
            $platform,
        ));

        return null;
    }

    /**
     * @return array{successful:bool,route_updated:bool,dialogs_pinned:int,warning:string}
     */
    private function pinDialogsForExistingRoute(
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        ?string $sourceId,
    ): array {
        $route = Bitrix24OpenLineRoute::query()
            ->with('callbackOwner')
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->first();

        if (! $route instanceof Bitrix24OpenLineRoute || ! $route->isUsable()) {
            return $this->failedOutcome(sprintf(
                'Профиль `%s`, канал #%d: нужен заранее опубликованный usable-маршрут `%s:%s`; backfill не создаёт и не активирует маршруты.',
                $profile->profile_key,
                $channel->id,
                $connectorCode,
                $lineId,
            ));
        }

        $channelType = Bitrix24OpenLineRoute::channelTypeForChannel($channel);
        $connectorType = Bitrix24OpenLineRoute::openLinesConnectorTypeForChannelType($channelType);
        $owner = $route->callbackOwner;

        if (! $this->routeMatchesIdentity(
            $route,
            $profile,
            $channel,
            $connectorCode,
            $lineId,
        )) {
            return $this->failedOutcome(sprintf(
                'Маршрут #%d не совпадает с канонической identity старой ОЛ `%s:%s`; backfill не изменён.',
                $route->id,
                $connectorCode,
                $lineId,
            ));
        }

        if (! is_string($connectorType)
            || ! $owner instanceof Bitrix24CallbackOwner
            || ! $owner->isActive()
        ) {
            return $this->failedOutcome(sprintf(
                'Маршрут #%d не имеет активного callback-владельца или поддерживаемого connector type; backfill не изменён.',
                $route->id,
            ));
        }

        return $this->routeOperationLock->runForOwnedLine(
            $profile,
            $owner,
            $connectorCode,
            $connectorType,
            $lineId,
            fn (
                Bitrix24OpenLineRouteLeaseDeadline $_leaseDeadline,
                Bitrix24OpenLineMutationAuthority $authority,
            ): array => $this->pinDialogsUnderAuthority(
                $profile,
                $channel,
                $owner,
                $connectorCode,
                $lineId,
                $sourceId,
                $authority,
            ),
            scope: Bitrix24OpenLineMutationAuthority::SCOPE_LINE_RUNTIME,
            route: $route,
            operationType: 'backfill_open_line_route',
        );
    }

    /**
     * @return array{successful:bool,route_updated:bool,dialogs_pinned:int,warning:string}
     */
    private function pinDialogsUnderAuthority(
        Bitrix24Profile $profile,
        Channel $channel,
        Bitrix24CallbackOwner $expectedOwner,
        string $connectorCode,
        string $lineId,
        ?string $sourceId,
        Bitrix24OpenLineMutationAuthority $authority,
    ): array {
        return $this->mutationFence->runMutation(
            $authority,
            function (?Bitrix24OpenLineRoute $route) use (
                $profile,
                $channel,
                $expectedOwner,
                $connectorCode,
                $lineId,
                $sourceId,
            ): array {
                $owner = Bitrix24CallbackOwner::query()
                    ->where('bitrix24_profile_id', $profile->id)
                    ->whereKey($expectedOwner->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $route instanceof Bitrix24OpenLineRoute
                    || ! $route->isUsable()
                    || ! $owner instanceof Bitrix24CallbackOwner
                    || ! $this->callbackOwnerMatchesIdentity($owner, $expectedOwner)
                    || (int) $route->callback_owner_id !== (int) $expectedOwner->getKey()
                    || ! $this->routeMatchesIdentity(
                        $route,
                        $profile,
                        $channel,
                        $connectorCode,
                        $lineId,
                    )
                ) {
                    return $this->failedOutcome(
                        'Маршрут или callback-владелец изменились после начала backfill; изменения не применены.',
                    );
                }

                $routeUpdated = false;

                if ($sourceId !== null && (string) $route->source_id !== $sourceId) {
                    $route->forceFill(['source_id' => $sourceId]);
                    $route->save();
                    $routeUpdated = true;
                }

                $dialogsPinned = Dialog::query()
                    ->where('channel_id', $channel->id)
                    ->whereNull('bitrix24_open_line_route_id')
                    ->whereNotNull('bitrix24_live_chat_id')
                    ->where('bitrix24_live_chat_id', '!=', '')
                    ->update([
                        'bitrix24_open_line_route_id' => $route->id,
                        'updated_at' => now(),
                    ]);

                return [
                    'successful' => true,
                    'route_updated' => $routeUpdated,
                    'dialogs_pinned' => $dialogsPinned,
                    'warning' => '',
                ];
            },
        );
    }

    private function routeMatchesIdentity(
        Bitrix24OpenLineRoute $route,
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
    ): bool {
        return (string) $route->portal_domain === (string) $profile->portal_domain
            && (string) $route->profile_key === (string) $profile->profile_key
            && (string) $route->channel_type === Bitrix24OpenLineRoute::channelTypeForChannel($channel)
            && (string) $route->connector_code === $connectorCode
            && (string) $route->line_id === $lineId;
    }

    private function callbackOwnerMatchesIdentity(
        Bitrix24CallbackOwner $owner,
        Bitrix24CallbackOwner $expectedOwner,
    ): bool {
        return $owner->isActive()
            && (int) $owner->bitrix24_profile_id === (int) $expectedOwner->bitrix24_profile_id
            && (string) $owner->owner_key === (string) $expectedOwner->owner_key
            && (string) $owner->callback_base_url === (string) $expectedOwner->callback_base_url
            && (string) $owner->status === (string) $expectedOwner->status;
    }

    /**
     * @return array{successful:false,route_updated:false,dialogs_pinned:0,warning:string}
     */
    private function failedOutcome(string $warning): array
    {
        return [
            'successful' => false,
            'route_updated' => false,
            'dialogs_pinned' => 0,
            'warning' => $warning,
        ];
    }

    /**
     * @param  array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}  $result
     */
    private function recordSkip(array &$result, string $warning): void
    {
        $result['skipped']++;
        $result['warnings'][] = $warning;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
