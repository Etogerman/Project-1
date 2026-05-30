<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoResolutionEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'dialog_id',
        'message_id',
        'status',
        'source_text',
        'matched_alias',
        'geo_alias_id',
        'country_id',
        'region_id',
        'city_id',
        'country',
        'region',
        'city',
        'confidence',
        'payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => 'integer',
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function alias(): BelongsTo
    {
        return $this->belongsTo(GeoAlias::class, 'geo_alias_id');
    }

    public function countryRecord(): BelongsTo
    {
        return $this->belongsTo(GeoCountry::class, 'country_id');
    }

    public function regionRecord(): BelongsTo
    {
        return $this->belongsTo(GeoRegion::class, 'region_id');
    }

    public function cityRecord(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }
}
