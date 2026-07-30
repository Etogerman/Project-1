<?php

namespace App\Services\Bitrix24;

use Closure;
use LogicException;

final class Bitrix24OpenLineMutationAuthorityContext
{
    /**
     * @var list<Bitrix24OpenLineMutationAuthority>
     */
    private array $stack = [];

    public function current(): ?Bitrix24OpenLineMutationAuthority
    {
        $authority = end($this->stack);

        return $authority instanceof Bitrix24OpenLineMutationAuthority ? $authority : null;
    }

    public function run(Bitrix24OpenLineMutationAuthority $authority, Closure $callback): mixed
    {
        $this->stack[] = $authority;

        try {
            return $callback($authority);
        } finally {
            $released = array_pop($this->stack);

            if ($released !== $authority) {
                $this->stack = [];

                throw new LogicException('Open Lines mutation authority stack was corrupted.');
            }
        }
    }
}
