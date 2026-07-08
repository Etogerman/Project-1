<?php

namespace App\Models;

use App\Services\Colors\ColorRegistry;
use App\Support\Colors\AbColorPalette;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoReplyCategory extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'color',
        'color_source',
        'color_value',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saving(function (AutoReplyCategory $category): void {
            $category->guardColor();
        });
    }

    public function rules(): HasMany
    {
        return $this->hasMany(AutoReplyRule::class)->orderBy('priority')->orderBy('id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => trim((string) $value),
        );
    }

    protected function guardColor(): void
    {
        $colorInput = is_string($this->color) ? trim($this->color) : null;

        $normalized = app(ColorRegistry::class)->normalizeForStorage(
            source: $this->isDirty('color') ? null : $this->color_source,
            value: $this->isDirty('color') ? $colorInput : $this->color_value,
            legacy: $colorInput,
        );

        $this->color_source = $normalized['color_source'];
        $this->color_value = $normalized['color_value'];
        $this->color = $normalized['legacy_color'];
    }

    public function getColorValueAttribute(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return is_string($this->color) && trim($this->color) !== ''
            ? $this->color
            : AbColorPalette::DEFAULT_PRESET_KEY;
    }
}
