<?php

namespace App\Services\Bitrix24;

use App\Models\Bitrix24OpenLineRoute;
use App\Models\Bitrix24Profile;
use App\Models\Channel;
use App\Models\Dialog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BackfillBitrix24OpenLineRoutesAction
{
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
            $result['skipped']++;
            $result['warnings'][] = sprintf(
                'Профиль `%s`: старая настройка ОЛ для `%s` неполная, connector или line не заполнены.',
                $profile->profile_key,
                $platform,
            );

            return;
        }

        DB::transaction(function () use ($profile, $platform, $connectorCode, $lineId, $sourceId, &$result): void {
            $channel = $this->resolveLegacyChannel($profile, $platform, $connectorCode, $lineId, $result);

            if (! $channel instanceof Channel) {
                return;
            }

            $route = $this->upsertLegacyRoute($profile, $channel, $connectorCode, $lineId, $sourceId, $result);

            if (! $route instanceof Bitrix24OpenLineRoute || ! $route->isUsable()) {
                return;
            }

            $result['dialogs_pinned'] += Dialog::query()
                ->where('channel_id', $channel->id)
                ->whereNull('bitrix24_open_line_route_id')
                ->whereNotNull('bitrix24_live_chat_id')
                ->where('bitrix24_live_chat_id', '!=', '')
                ->update([
                    'bitrix24_open_line_route_id' => $route->id,
                    'updated_at' => now(),
                ]);
        });
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

        if ($existingRoute instanceof Bitrix24OpenLineRoute && $existingRoute->channel instanceof Channel) {
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
            $result['skipped']++;
            $result['warnings'][] = sprintf(
                'Для профиля `%s` не найден локальный канал платформы `%s` под старую ОЛ `%s:%s`.',
                $profile->profile_key,
                $platform,
                $connectorCode,
                $lineId,
            );

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

        $result['skipped']++;
        $result['warnings'][] = sprintf(
            'Профиль `%s`: старую ОЛ `%s:%s` нельзя безопасно привязать автоматически, найдено несколько каналов `%s`.',
            $profile->profile_key,
            $connectorCode,
            $lineId,
            $platform,
        );

        return null;
    }

    /**
     * @param  array{routes_created:int,routes_updated:int,dialogs_pinned:int,skipped:int,warnings:list<string>}  $result
     */
    private function upsertLegacyRoute(
        Bitrix24Profile $profile,
        Channel $channel,
        string $connectorCode,
        string $lineId,
        ?string $sourceId,
        array &$result,
    ): ?Bitrix24OpenLineRoute {
        $route = Bitrix24OpenLineRoute::query()
            ->where('bitrix24_profile_id', $profile->id)
            ->where('channel_id', $channel->id)
            ->first();

        if ($route instanceof Bitrix24OpenLineRoute) {
            if ($route->isUsable()
                && ((string) $route->connector_code !== $connectorCode || (string) $route->line_id !== $lineId)) {
                $result['skipped']++;
                $result['warnings'][] = sprintf(
                    'Маршрут #%d уже использует другую ОЛ, старые значения `%s:%s` не применены.',
                    $route->id,
                    $connectorCode,
                    $lineId,
                );

                return null;
            }

            $existingOwner = Bitrix24OpenLineRoute::query()
                ->where('line_owner_key', sprintf('%s#%s', $profile->portal_domain, $lineId))
                ->whereKeyNot($route->getKey())
                ->first();

            if ($existingOwner instanceof Bitrix24OpenLineRoute) {
                $result['skipped']++;
                $result['warnings'][] = sprintf(
                    'ОЛ `%s:%s` уже занята маршрутом #%d, маршрут #%d не переведён в legacy.',
                    $connectorCode,
                    $lineId,
                    $existingOwner->id,
                    $route->id,
                );

                return null;
            }

            $updates = [
                'portal_domain' => $profile->portal_domain,
                'profile_key' => $profile->profile_key,
                'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
                'connector_code' => $connectorCode,
                'line_id' => $lineId,
                'source_id' => $sourceId,
            ];

            if (! $route->isUsable()) {
                $updates['status'] = Bitrix24OpenLineRoute::STATUS_LEGACY;
            }

            $route->forceFill($updates);

            if ($route->isDirty()) {
                $route->save();
                $result['routes_updated']++;
            }

            return $route->fresh();
        }

        $existingOwner = Bitrix24OpenLineRoute::query()
            ->where('line_owner_key', sprintf('%s#%s', $profile->portal_domain, $lineId))
            ->first();

        if ($existingOwner instanceof Bitrix24OpenLineRoute) {
            $result['skipped']++;
            $result['warnings'][] = sprintf(
                'ОЛ `%s:%s` уже занята маршрутом #%d, новый legacy-маршрут для канала #%d не создан.',
                $connectorCode,
                $lineId,
                $existingOwner->id,
                $channel->id,
            );

            return null;
        }

        $result['routes_created']++;

        return Bitrix24OpenLineRoute::query()->create([
            'bitrix24_profile_id' => $profile->id,
            'channel_id' => $channel->id,
            'portal_domain' => $profile->portal_domain,
            'profile_key' => $profile->profile_key,
            'channel_type' => Bitrix24OpenLineRoute::channelTypeForChannel($channel),
            'connector_code' => $connectorCode,
            'line_id' => $lineId,
            'source_id' => $sourceId,
            'status' => Bitrix24OpenLineRoute::STATUS_LEGACY,
        ]);
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
