<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class QuestionnaireTemplate extends Model
{
    public const KEY_PROFILE = 'profile';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DISABLED = 'disabled';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'name',
        'status',
        'published_version_id',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::saving(function (QuestionnaireTemplate $template): void {
            $template->key = self::normalizeKey($template->key);
            $template->name = trim((string) $template->name);
        });
    }

    public static function normalizeKey(mixed $key): string
    {
        return Str::of(is_scalar($key) ? (string) $key : '')
            ->trim()
            ->lower()
            ->replaceMatches('/[^a-z0-9_]+/', '_')
            ->trim('_')
            ->limit(80, '')
            ->toString();
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            self::STATUS_DRAFT => 'Черновик',
            self::STATUS_PUBLISHED => 'Опубликована',
            self::STATUS_DISABLED => 'Отключена',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(QuestionnaireTemplateVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionnaireTemplateVersion::class, 'published_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ContactQuestionnaireRun::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
