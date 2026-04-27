<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ScenarioVersion extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_id',
        'version_number',
        'status',
        'schema_payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'version_number' => 'integer',
        'schema_payload' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (ScenarioVersion $version): void {
            $version->guardVersionNumber();
            $version->guardStatus();
            $version->guardSchemaPayload();
        });
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Draft',
            self::STATUS_PUBLISHED => 'Published',
            self::STATUS_ARCHIVED => 'Archived',
        ];
    }

    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    public function builderBlocks(): HasMany
    {
        return $this->hasMany(ScenarioBuilderBlock::class);
    }

    public function builderEdges(): HasMany
    {
        return $this->hasMany(ScenarioBuilderEdge::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    protected function guardVersionNumber(): void
    {
        $versionNumber = (int) $this->version_number;

        if ($versionNumber < 1) {
            throw ValidationException::withMessages([
                'version_number' => 'Номер версии должен быть положительным.',
            ]);
        }

        $this->version_number = $versionNumber;
    }

    protected function guardStatus(): void
    {
        $status = is_string($this->status)
            ? trim($this->status)
            : null;

        if (! in_array($status, array_keys(self::statusOptions()), true)) {
            throw ValidationException::withMessages([
                'status' => 'Неизвестный статус версии сценария.',
            ]);
        }

        $this->status = $status;
    }

    protected function guardSchemaPayload(): void
    {
        $schemaPayload = $this->schema_payload;

        if ($schemaPayload === null) {
            $this->schema_payload = [];

            return;
        }

        if (! is_array($schemaPayload)) {
            throw ValidationException::withMessages([
                'schema_payload' => 'Схема сценария должна быть JSON-объектом.',
            ]);
        }
    }
}
