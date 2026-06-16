<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CardView extends Model
{
    public const ENTITY_CONTACT = 'contact';

    public const ENTITY_DIALOG = 'dialog';

    public const CONTEXT_CARD = 'card';

    public const SCOPE_SYSTEM = 'system';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'entity',
        'context',
        'view_key',
        'name',
        'is_system',
        'scope',
        'is_default',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_system' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function tabs(): HasMany
    {
        return $this->hasMany(CardViewTab::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function visibleTabs(): HasMany
    {
        return $this->tabs()
            ->where('is_visible', true);
    }
}
