<?php

namespace App\Data\Messages;

final readonly class PinnedHttpsUrlData
{
    /**
     * @param  array<int, mixed>  $curlOptions
     */
    public function __construct(
        public string $url,
        public array $curlOptions,
    ) {}
}
