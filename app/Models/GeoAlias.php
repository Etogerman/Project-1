<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoAlias extends Model
{
    public const TYPE_CANONICAL = 'canonical';

    public const TYPE_SHORT = 'short';

    public const TYPE_TRANSLIT = 'translit';

    public const TYPE_CASE_FORM = 'case_form';

    public const TYPE_OLD_NAME = 'old_name';

    public const TYPE_SLANG = 'slang';

    public const TYPE_TYPO = 'typo';

    public const TYPE_FOREIGN_NAME = 'foreign_name';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'alias',
        'normalized_alias',
        'city_id',
        'language',
        'alias_type',
        'confidence',
        'auto_apply',
        'active',
        'comment',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'confidence' => 'integer',
        'auto_apply' => 'boolean',
        'active' => 'boolean',
    ];

    public function city(): BelongsTo
    {
        return $this->belongsTo(GeoCity::class, 'city_id');
    }
}
