<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoRegion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'code',
        'name_ru',
        'name_en',
        'normalized_name',
        'type',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(GeoCountry::class, 'country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'region_id');
    }
}
