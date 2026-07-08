<?php

namespace App\Filament\Forms\Components;

use App\Services\Colors\ColorRegistry;
use App\Support\Colors\AbColorPalette;
use Filament\Forms\Components\ViewField;
use Illuminate\Database\Eloquent\Model;

class ColorPicker extends ViewField
{
    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->default(AbColorPalette::DEFAULT_PRESET_KEY)
            ->view('filament.colors.components.color-picker', fn (): array => [
                'recommendedColors' => app(ColorRegistry::class)->recommended(),
                'webSafeColors' => app(ColorRegistry::class)->webSafe(),
            ])
            ->formatStateUsing(function (mixed $state, ?Model $record): string {
                return app(ColorRegistry::class)->inputValue(
                    source: $record?->getAttribute('color_source'),
                    value: $record?->getAttribute('color_value'),
                    legacy: is_string($state) ? $state : null,
                );
            });
    }
}
