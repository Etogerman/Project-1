<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoCity extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'country_id',
        'region_id',
        'name_ru',
        'name_en',
        'normalized_name',
        'population',
        'lat',
        'lon',
        'timezone',
        'source',
        'source_id',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'population' => 'integer',
        'lat' => 'decimal:7',
        'lon' => 'decimal:7',
        'active' => 'boolean',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(GeoCountry::class, 'country_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(GeoRegion::class, 'region_id');
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(GeoAlias::class, 'city_id');
    }
}
