<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GeoCountry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'iso2',
        'iso3',
        'name_ru',
        'name_en',
        'normalized_name',
        'active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'active' => 'boolean',
    ];

    public function regions(): HasMany
    {
        return $this->hasMany(GeoRegion::class, 'country_id');
    }

    public function cities(): HasMany
    {
        return $this->hasMany(GeoCity::class, 'country_id');
    }
}
