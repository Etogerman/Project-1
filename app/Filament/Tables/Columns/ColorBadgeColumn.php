<?php

namespace App\Filament\Tables\Columns;

use App\Services\Colors\ColorRegistry;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class ColorBadgeColumn
{
    public static function make(string $name = 'color'): TextColumn
    {
        return TextColumn::make($name)
            ->html()
            ->state(fn (Model $record): HtmlString => self::render($record));
    }

    private static function render(Model $record): HtmlString
    {
        $color = app(ColorRegistry::class)->resolve(
            source: self::nullableString($record->getAttribute('color_source')),
            value: self::nullableString($record->getAttribute('color_value')),
            legacy: self::nullableString($record->getAttribute('color')),
        );

        return new HtmlString(sprintf(
            '<span class="ac-color-chip" data-color-chip data-color-value="%s" data-color-hex="%s" title="%s" style="display:inline-flex;align-items:center;max-width:100%%;min-height:1.5rem;border-radius:9999px;padding:0 .55rem;border:1px solid rgba(15,23,42,.22);background:%s;color:%s;box-shadow:inset 0 0 0 1px rgba(255,255,255,.24);font-size:.75rem;font-weight:700;line-height:1;"><span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">%s</span></span>',
            e((string) $color['value']),
            e((string) $color['hex']),
            e(((string) $color['name']).' '.((string) $color['hex'])),
            e((string) $color['background']),
            e((string) $color['text']),
            e((string) $color['name']),
        ));
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
