<?php

namespace App\Support\Colors;

class AbColorPalette
{
    public const ENTITY_SOURCE_PRESET = 'preset';

    public const ENTITY_SOURCE_CUSTOM = 'custom';

    public const PRESET_SOURCE_RECOMMENDED = 'recommended';

    public const PRESET_SOURCE_WEB_SAFE = 'web_safe';

    public const DEFAULT_PRESET_KEY = 'ab_slate';

    /**
     * @return list<array{key:string,name:string,hex:string,source:string,is_recommended:bool,sort_order:int,is_active:bool}>
     */
    public static function recommended(): array
    {
        return [
            ['key' => 'ab_slate', 'name' => 'Сланцевый', 'hex' => '#666666'],
            ['key' => 'ab_gray', 'name' => 'Серый', 'hex' => '#999999'],
            ['key' => 'ab_sky', 'name' => 'Небесный', 'hex' => '#0099FF'],
            ['key' => 'ab_blue', 'name' => 'Синий', 'hex' => '#3366CC'],
            ['key' => 'ab_navy', 'name' => 'Тёмно-синий', 'hex' => '#003399'],
            ['key' => 'ab_cyan', 'name' => 'Циан', 'hex' => '#00CCCC'],
            ['key' => 'ab_teal', 'name' => 'Бирюзовый', 'hex' => '#009999'],
            ['key' => 'ab_aqua', 'name' => 'Голубой', 'hex' => '#66CCFF'],
            ['key' => 'ab_emerald', 'name' => 'Изумрудный', 'hex' => '#009966'],
            ['key' => 'ab_green', 'name' => 'Зелёный', 'hex' => '#339933'],
            ['key' => 'ab_mint', 'name' => 'Мятный', 'hex' => '#66CC99'],
            ['key' => 'ab_lime', 'name' => 'Лаймовый', 'hex' => '#99CC00'],
            ['key' => 'ab_yellow', 'name' => 'Жёлтый', 'hex' => '#FFCC00'],
            ['key' => 'ab_amber', 'name' => 'Янтарный', 'hex' => '#CC9900'],
            ['key' => 'ab_orange', 'name' => 'Оранжевый', 'hex' => '#FF9900'],
            ['key' => 'ab_coral', 'name' => 'Коралловый', 'hex' => '#FF6633'],
            ['key' => 'ab_red', 'name' => 'Красный', 'hex' => '#CC0000'],
            ['key' => 'ab_rose', 'name' => 'Розово-красный', 'hex' => '#CC0066'],
            ['key' => 'ab_pink', 'name' => 'Розовый', 'hex' => '#FF66CC'],
            ['key' => 'ab_magenta', 'name' => 'Маджента', 'hex' => '#CC00CC'],
            ['key' => 'ab_violet', 'name' => 'Фиолетовый', 'hex' => '#9933CC'],
            ['key' => 'ab_purple', 'name' => 'Пурпурный', 'hex' => '#6600CC'],
            ['key' => 'ab_indigo', 'name' => 'Индиго', 'hex' => '#333399'],
            ['key' => 'ab_brown', 'name' => 'Коричневый', 'hex' => '#996633'],
        ];
    }

    /**
     * @return list<array{key:string,name:string,hex:string,source:string,is_recommended:bool,sort_order:int,is_active:bool}>
     */
    public static function recommendedPresets(): array
    {
        return array_map(
            fn (array $color, int $index): array => [
                ...$color,
                'source' => self::PRESET_SOURCE_RECOMMENDED,
                'is_recommended' => true,
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ],
            self::recommended(),
            array_keys(self::recommended()),
        );
    }

    /**
     * @return list<array{key:string,name:string,hex:string,source:string,is_recommended:bool,sort_order:int,is_active:bool}>
     */
    public static function webSafePresets(): array
    {
        $steps = [0, 51, 102, 153, 204, 255];
        $presets = [];
        $sortOrder = 10;

        foreach ($steps as $red) {
            foreach ($steps as $green) {
                foreach ($steps as $blue) {
                    $hex = sprintf('#%02X%02X%02X', $red, $green, $blue);
                    $presets[] = [
                        'key' => 'web_safe_'.strtolower(ltrim($hex, '#')),
                        'name' => 'Web-safe '.$hex,
                        'hex' => $hex,
                        'source' => self::PRESET_SOURCE_WEB_SAFE,
                        'is_recommended' => false,
                        'sort_order' => $sortOrder,
                        'is_active' => true,
                    ];
                    $sortOrder += 10;
                }
            }
        }

        return $presets;
    }

    /**
     * @return list<array{key:string,name:string,hex:string,source:string,is_recommended:bool,sort_order:int,is_active:bool}>
     */
    public static function presets(): array
    {
        return [
            ...self::recommendedPresets(),
            ...self::webSafePresets(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function legacyAliases(): array
    {
        return [
            'gray' => 'ab_slate',
            'info' => 'ab_sky',
            'blue' => 'ab_blue',
            'primary' => 'ab_blue',
            'success' => 'ab_emerald',
            'green' => 'ab_emerald',
            'warning' => 'ab_amber',
            'yellow' => 'ab_amber',
            'danger' => 'ab_red',
            'red' => 'ab_red',
            'purple' => 'ab_violet',
            'teal' => 'ab_teal',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function presetFilamentTones(): array
    {
        return [
            'ab_slate' => 'gray',
            'ab_gray' => 'gray',
            'ab_sky' => 'info',
            'ab_blue' => 'primary',
            'ab_navy' => 'primary',
            'ab_cyan' => 'info',
            'ab_teal' => 'info',
            'ab_aqua' => 'info',
            'ab_emerald' => 'success',
            'ab_green' => 'success',
            'ab_mint' => 'success',
            'ab_lime' => 'success',
            'ab_yellow' => 'warning',
            'ab_amber' => 'warning',
            'ab_orange' => 'warning',
            'ab_coral' => 'warning',
            'ab_red' => 'danger',
            'ab_rose' => 'danger',
            'ab_pink' => 'danger',
            'ab_magenta' => 'danger',
            'ab_violet' => 'primary',
            'ab_purple' => 'primary',
            'ab_indigo' => 'primary',
            'ab_brown' => 'warning',
        ];
    }

    public static function normalizeHex(?string $hex): ?string
    {
        $hex = trim((string) $hex);

        if ($hex === '') {
            return null;
        }

        if (! str_starts_with($hex, '#')) {
            $hex = '#'.$hex;
        }

        $hex = strtoupper($hex);

        return preg_match('/^#[0-9A-F]{6}$/', $hex) === 1 ? $hex : null;
    }

    /**
     * @return array{r:int,g:int,b:int}
     */
    public static function rgb(string $hex): array
    {
        $hex = self::normalizeHex($hex) ?? '#666666';

        return [
            'r' => hexdec(substr($hex, 1, 2)),
            'g' => hexdec(substr($hex, 3, 2)),
            'b' => hexdec(substr($hex, 5, 2)),
        ];
    }
}
