<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Tag extends Model
{
    use HasFactory;

    public const COLOR_GRAY = 'gray';

    public const COLOR_PRIMARY = 'primary';

    public const COLOR_SUCCESS = 'success';

    public const COLOR_WARNING = 'warning';

    public const COLOR_DANGER = 'danger';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'color',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Tag $tag): void {
            $tag->guardName();
            $tag->guardColor();
            $tag->synchronizeSlug();
        });

        static::deleting(function (Tag $tag): void {
            if (! $tag->contacts()->exists()) {
                return;
            }

            throw ValidationException::withMessages([
                'tag' => 'Нельзя удалить тег, который уже назначен контактам. Сначала снимите назначения или деактивируйте тег.',
            ]);
        });
    }

    /**
     * @return array<string, string>
     */
    public static function colorOptions(): array
    {
        return [
            self::COLOR_GRAY => 'Серый',
            self::COLOR_PRIMARY => 'Синий',
            self::COLOR_SUCCESS => 'Зелёный',
            self::COLOR_WARNING => 'Жёлтый',
            self::COLOR_DANGER => 'Красный',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)
            ->withPivot([
                'assigned_at',
                'assigned_by_user_id',
            ])
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return (bool) $this->is_active;
    }

    protected function guardName(): void
    {
        $name = is_string($this->name) ? trim($this->name) : '';

        if ($name === '') {
            throw ValidationException::withMessages([
                'name' => 'Нужно указать название тега.',
            ]);
        }

        $this->name = $name;
    }

    protected function guardColor(): void
    {
        $color = is_string($this->color) ? trim($this->color) : '';

        if (! array_key_exists($color, self::colorOptions())) {
            throw ValidationException::withMessages([
                'color' => 'Нужно выбрать допустимый цвет тега.',
            ]);
        }

        $this->color = $color;
    }

    protected function synchronizeSlug(): void
    {
        $slug = Str::slug((string) $this->name, '-', 'ru');

        if ($slug === '') {
            throw ValidationException::withMessages([
                'name' => 'Не удалось сформировать код тега из названия.',
            ]);
        }

        $exists = static::query()
            ->where('slug', $slug)
            ->when($this->exists, fn (Builder $query): Builder => $query->whereKeyNot($this->getKey()))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'name' => 'Тег с таким кодом уже существует. Выберите другое название.',
            ]);
        }

        $this->slug = $slug;
    }
}
