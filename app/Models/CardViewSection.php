<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardViewSection extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'card_view_tab_id',
        'section_key',
        'name',
        'sort_order',
        'is_visible',
        'is_collapsed_by_default',
        'is_system',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
        'is_visible' => 'boolean',
        'is_collapsed_by_default' => 'boolean',
        'is_system' => 'boolean',
    ];

    public function tab(): BelongsTo
    {
        return $this->belongsTo(CardViewTab::class, 'card_view_tab_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CardViewItem::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function visibleItems(): HasMany
    {
        return $this->items()
            ->where('is_visible', true);
    }
}
