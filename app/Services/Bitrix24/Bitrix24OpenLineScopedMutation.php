<?php

namespace App\Services\Bitrix24;

use Closure;
use Throwable;

final class Bitrix24OpenLineScopedMutation
{
    public function __construct(
        private readonly Bitrix24OpenLineMutationAuthorityContext $authorityContext,
        private readonly Bitrix24OpenLineRouteMutationFence $mutationFence,
    ) {}

    public function run(Closure $callback): mixed
    {
        $authority = $this->authorityContext->current();

        if (! $authority instanceof Bitrix24OpenLineMutationAuthority) {
            return $callback();
        }

        return $this->mutationFence->runMutation($authority, $callback);
    }

    public function runBestEffort(Closure $callback): mixed
    {
        if (! $this->authorityContext->current() instanceof Bitrix24OpenLineMutationAuthority) {
            return $callback();
        }

        try {
            return $this->run($callback);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    public function assertCurrent(): void
    {
        $authority = $this->authorityContext->current();

        if (! $authority instanceof Bitrix24OpenLineMutationAuthority) {
            return;
        }

        $authority->deadline->assertAvailableFor(1);
        $this->mutationFence->assertCurrent($authority);
    }
}
