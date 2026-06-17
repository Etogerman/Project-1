<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardViewTab extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'card_view_id',
        'tab_key',
        'name',
        'sort_order',
        'is_visible',
        'is_system',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function view(): BelongsTo
    {
        return $this->belongsTo(CardView::class, 'card_view_id');
    }

    public function sections(): HasMany
    {
        return $this->hasMany(CardViewSection::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function visibleSections(): HasMany
    {
        return $this->sections()
            ->where('is_visible', true);
    }
}
