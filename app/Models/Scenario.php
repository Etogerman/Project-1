<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Scenario extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'is_active',
        'is_archived',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (Scenario $scenario): void {
            $scenario->guardCode();
            $scenario->guardName();
        });
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ScenarioVersion::class);
    }

    public function publishedVersion(): HasOne
    {
        return $this->hasOne(ScenarioVersion::class)
            ->where('status', ScenarioVersion::STATUS_PUBLISHED)
            ->latestOfMany('version_number');
    }

    public function draftVersion(): HasOne
    {
        return $this->hasOne(ScenarioVersion::class)
            ->where('status', ScenarioVersion::STATUS_DRAFT)
            ->latestOfMany('version_number');
    }

    protected function guardCode(): void
    {
        $normalizedCode = is_string($this->code)
            ? trim($this->code)
            : null;

        if (! filled($normalizedCode)) {
            throw ValidationException::withMessages([
                'code' => 'Нужно указать код сценария.',
            ]);
        }

        if (
            $this->exists
            && $this->isDirty('code')
            && $normalizedCode !== (string) $this->getOriginal('code')
        ) {
            throw ValidationException::withMessages([
                'code' => 'Код сценария нельзя менять после создания.',
            ]);
        }

        $this->code = $normalizedCode;
    }

    protected function guardName(): void
    {
        $normalizedName = is_string($this->name)
            ? trim($this->name)
            : null;

        if (! filled($normalizedName)) {
            throw ValidationException::withMessages([
                'name' => 'Нужно указать название сценария.',
            ]);
        }

        $this->name = $normalizedName;
    }
}
