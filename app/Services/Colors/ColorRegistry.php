<?php

namespace App\Services\Colors;

use App\Models\ColorPreset;
use App\Support\Colors\AbColorPalette;
use Illuminate\Support\Facades\Schema;

class ColorRegistry
{
    /**
     * @return array{color_source:string,color_value:string,legacy_color:string}
     */
    public function normalizeForStorage(?string $source, ?string $value, ?string $legacy = null): array
    {
        $resolved = $this->resolve($source, $value, $legacy);

        return [
            'color_source' => $resolved['source'],
            'color_value' => $resolved['value'],
            'legacy_color' => $resolved['filament_tone'],
        ];
    }

    /**
     * @return array{source:string,value:string,key:?string,name:string,hex:string,background:string,soft:string,border:string,text:string,filament_tone:string}
     */
    public function resolve(?string $source, ?string $value, ?string $legacy = null): array
    {
        $value = trim((string) $value);
        $legacy = trim((string) $legacy);

        if ($hex = AbColorPalette::normalizeHex($value)) {
            return $this->custom($hex);
        }

        $key = $this->normalizePresetKey($value);

        if ($key === null && $legacy !== '') {
            $key = $this->normalizePresetKey($legacy);
        }

        $key ??= AbColorPalette::DEFAULT_PRESET_KEY;

        if ($preset = $this->findPreset($key)) {
            return $this->preset($preset);
        }

        return $this->preset($this->fallbackPreset(AbColorPalette::DEFAULT_PRESET_KEY));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recommended(): array
    {
        return $this->presetsBySource(AbColorPalette::PRESET_SOURCE_RECOMMENDED);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function webSafe(): array
    {
        return $this->presetsBySource(AbColorPalette::PRESET_SOURCE_WEB_SAFE);
    }

    public function legacyTone(?string $source, ?string $value, ?string $legacy = null): string
    {
        return $this->resolve($source, $value, $legacy)['filament_tone'];
    }

    public function inputValue(?string $source, ?string $value, ?string $legacy = null): string
    {
        return $this->resolve($source, $value, $legacy)['value'];
    }

    public function normalizeInputValue(mixed $value, bool $allowNone = false): ?string
    {
        $value = trim((string) $value);

        if ($allowNone && ($value === '' || $value === 'none')) {
            return 'none';
        }

        if ($value === '') {
            return null;
        }

        if ($hex = AbColorPalette::normalizeHex($value)) {
            return $hex;
        }

        $key = $this->normalizePresetKey($value);

        return $key !== null && $this->findPreset($key) !== null ? $key : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presetsBySource(string $source): array
    {
        if (Schema::hasTable('color_presets')) {
            return ColorPreset::query()
                ->active()
                ->where('source', $source)
                ->ordered()
                ->get()
                ->map(fn (ColorPreset $preset): array => $this->preset($preset->only([
                    'key',
                    'name',
                    'hex',
                    'source',
                    'is_recommended',
                    'sort_order',
                    'is_active',
                ])))
                ->all();
        }

        return collect(AbColorPalette::presets())
            ->where('source', $source)
            ->values()
            ->map(fn (array $preset): array => $this->preset($preset))
            ->all();
    }

    private function normalizePresetKey(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $aliases = AbColorPalette::legacyAliases();

        return $aliases[$value] ?? $value;
    }

    /**
     * @return ?array<string, mixed>
     */
    private function findPreset(string $key): ?array
    {
        if (Schema::hasTable('color_presets')) {
            $preset = ColorPreset::query()
                ->active()
                ->where('key', $key)
                ->first();

            if ($preset instanceof ColorPreset) {
                return $preset->only([
                    'key',
                    'name',
                    'hex',
                    'source',
                    'is_recommended',
                    'sort_order',
                    'is_active',
                ]);
            }
        }

        return collect(AbColorPalette::presets())
            ->firstWhere('key', $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function fallbackPreset(string $key): array
    {
        return collect(AbColorPalette::presets())
            ->firstWhere('key', $key)
            ?? AbColorPalette::recommendedPresets()[0];
    }

    /**
     * @param  array<string, mixed>  $preset
     * @return array{source:string,value:string,key:string,name:string,hex:string,background:string,soft:string,border:string,text:string,filament_tone:string}
     */
    private function preset(array $preset): array
    {
        $hex = AbColorPalette::normalizeHex((string) $preset['hex']) ?? '#666666';
        $tokens = $this->tokens($hex);
        $key = (string) $preset['key'];

        return [
            'source' => AbColorPalette::ENTITY_SOURCE_PRESET,
            'value' => $key,
            'key' => $key,
            'name' => (string) $preset['name'],
            'hex' => $hex,
            'filament_tone' => AbColorPalette::presetFilamentTones()[$key] ?? 'gray',
            ...$tokens,
        ];
    }

    /**
     * @return array{source:string,value:string,key:null,name:string,hex:string,background:string,soft:string,border:string,text:string,filament_tone:string}
     */
    private function custom(string $hex): array
    {
        $tokens = $this->tokens($hex);

        return [
            'source' => AbColorPalette::ENTITY_SOURCE_CUSTOM,
            'value' => $hex,
            'key' => null,
            'name' => $hex,
            'hex' => $hex,
            'filament_tone' => 'gray',
            ...$tokens,
        ];
    }

    /**
     * @return array{background:string,soft:string,border:string,text:string}
     */
    private function tokens(string $hex): array
    {
        $rgb = AbColorPalette::rgb($hex);
        $luminance = (($rgb['r'] * 0.299) + ($rgb['g'] * 0.587) + ($rgb['b'] * 0.114)) / 255;

        return [
            'background' => $hex,
            'soft' => sprintf('rgba(%d, %d, %d, 0.16)', $rgb['r'], $rgb['g'], $rgb['b']),
            'border' => sprintf('rgba(%d, %d, %d, 0.45)', $rgb['r'], $rgb['g'], $rgb['b']),
            'text' => $luminance > 0.62 ? '#111827' : '#FFFFFF',
        ];
    }
}
