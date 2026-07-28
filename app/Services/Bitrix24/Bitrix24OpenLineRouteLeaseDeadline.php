<?php

namespace App\Services\Bitrix24;

use Carbon\CarbonImmutable;
use Throwable;

final class Bitrix24OpenLineRouteLeaseDeadline
{
    private function __construct(
        private readonly CarbonImmutable $deadline,
        private readonly int $safetyMarginSeconds,
    ) {}

    public static function fromRegistryLease(
        string $expiresAt,
        int $leaseSeconds,
        int $acquisitionBudgetSeconds,
    ): self {
        try {
            $registryDeadline = CarbonImmutable::createFromFormat(DATE_ATOM, $expiresAt);
        } catch (Throwable) {
            $registryDeadline = null;
        }

        if (! $registryDeadline instanceof CarbonImmutable
            || $registryDeadline->format(DATE_ATOM) !== $expiresAt
        ) {
            throw new Bitrix24OpenLinesRouteRegistryException(
                'route_registry_line_lease_response_invalid',
                'OpenLines registry вернул некорректный срок operation lease.',
            );
        }

        $leaseSeconds = max(1, $leaseSeconds);
        $localDeadline = CarbonImmutable::now()->addSeconds(
            max(1, $leaseSeconds - max(0, $acquisitionBudgetSeconds)),
        );
        $deadline = $registryDeadline->lessThan($localDeadline)
            ? $registryDeadline
            : $localDeadline;

        return new self(
            $deadline,
            max(30, (int) ceil($leaseSeconds * 0.2)),
        );
    }

    public function assertAvailableFor(int $operationBudgetSeconds): void
    {
        $requiredSeconds = max(0, $operationBudgetSeconds) + $this->safetyMarginSeconds;

        if (CarbonImmutable::now()->addSeconds($requiredSeconds)->lessThan($this->deadline)) {
            return;
        }

        throw new Bitrix24OpenLinesRouteRegistryException(
            'route_registry_line_lease_expiring',
            'Общая operation lease закончится раньше безопасного завершения операции.',
        );
    }
}
