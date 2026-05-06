<?php

namespace App\Console\Commands;

use App\Models\Bitrix24Connection;
use App\Models\Bitrix24OpenLineRoute;
use App\Services\Bitrix24\AutoSetupBitrix24OpenLineRouteAction;
use App\Services\Bitrix24\Bitrix24OpenLineAutoSetupException;
use Illuminate\Console\Command;

class Bitrix24RefreshOpenLineConnectorsCommand extends Command
{
    protected $signature = 'bitrix24:refresh-openline-connectors
        {--connection= : ID Bitrix24-подключения}
        {--route= : ID маршрута открытой линии}
        {--dry-run : Показать маршруты без вызовов Bitrix24}';

    protected $description = 'Refresh Bitrix24 Open Lines connector names and icons without recreating open lines.';

    public function __construct(
        private readonly AutoSetupBitrix24OpenLineRouteAction $autoSetupBitrix24OpenLineRouteAction,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $connection = $this->resolveConnection();

        if ($this->option('connection') !== null && ! $connection instanceof Bitrix24Connection) {
            return self::FAILURE;
        }

        $routes = Bitrix24OpenLineRoute::query()
            ->with(['bitrix24Profile', 'channel'])
            ->whereIn('status', [
                Bitrix24OpenLineRoute::STATUS_ACTIVE,
                Bitrix24OpenLineRoute::STATUS_LEGACY,
                Bitrix24OpenLineRoute::STATUS_MISCONFIGURED,
            ])
            ->when($connection instanceof Bitrix24Connection, fn ($query) => $query->where('bitrix24_profile_id', $connection->profile_id))
            ->when($this->routeId() !== null, fn ($query) => $query->whereKey($this->routeId()))
            ->orderBy('id')
            ->get();

        if ($routes->isEmpty()) {
            $this->warn('Подходящие маршруты ОЛ не найдены.');

            return self::SUCCESS;
        }

        $rows = [];
        $failed = 0;

        foreach ($routes as $route) {
            $routeConnection = $connection instanceof Bitrix24Connection
                ? $connection
                : $this->activeConnectionForRoute($route);

            if (! $routeConnection instanceof Bitrix24Connection) {
                $failed++;
                $rows[] = $this->row($route, 'Ошибка', 'Не найдено активное Bitrix24-подключение для профиля маршрута.');

                continue;
            }

            if ($this->option('dry-run')) {
                $rows[] = $this->row($route, 'Будет обновлён', 'Вызовы Bitrix24 не выполнялись.');

                continue;
            }

            try {
                $this->autoSetupBitrix24OpenLineRouteAction->refreshConnectorRegistration($routeConnection, $route);
                $rows[] = $this->row($route->refresh(), 'Обновлён', 'Ошибок не было');
            } catch (Bitrix24OpenLineAutoSetupException $exception) {
                $failed++;
                $rows[] = $this->row($route->refresh(), 'Ошибка', $exception->getMessage());
            }
        }

        $this->table(
            ['Route ID', 'Канал', 'Тип', 'Соединитель', 'Линия', 'Результат', 'Причина'],
            $rows,
        );

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function resolveConnection(): ?Bitrix24Connection
    {
        $connectionId = $this->connectionId();

        if ($connectionId === null) {
            return null;
        }

        $connection = Bitrix24Connection::query()
            ->with('profile')
            ->find($connectionId);

        if (! $connection instanceof Bitrix24Connection) {
            $this->error('Bitrix24-подключение #'.$connectionId.' не найдено.');

            return null;
        }

        return $connection;
    }

    private function activeConnectionForRoute(Bitrix24OpenLineRoute $route): ?Bitrix24Connection
    {
        return Bitrix24Connection::query()
            ->where('profile_id', $route->bitrix24_profile_id)
            ->where('status', Bitrix24Connection::STATUS_ACTIVE)
            ->latest('id')
            ->first();
    }

    private function connectionId(): ?int
    {
        $value = $this->option('connection');

        return is_scalar($value) && trim((string) $value) !== ''
            ? (int) $value
            : null;
    }

    private function routeId(): ?int
    {
        $value = $this->option('route');

        return is_scalar($value) && trim((string) $value) !== ''
            ? (int) $value
            : null;
    }

    /**
     * @return list<string>
     */
    private function row(Bitrix24OpenLineRoute $route, string $result, string $reason): array
    {
        return [
            (string) $route->id,
            '#'.$route->channel_id.' '.($route->channel?->name ?? 'Канал не найден'),
            (string) $route->channel_type,
            (string) $route->connector_code,
            (string) $route->line_id,
            $result,
            $reason,
        ];
    }
}
