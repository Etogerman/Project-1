<?php

namespace App\Services\Geo;

class GeoTextNormalizer
{
    public function handle(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = str_replace('ё', 'е', mb_strtolower(trim($value)));
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    public function forMatching(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        return str_replace('ё', 'е', mb_strtolower($value));
    }
}
