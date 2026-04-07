<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutoReplyCategory extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function rules(): HasMany
    {
        return $this->hasMany(AutoReplyRule::class)->orderBy('priority')->orderBy('id');
    }

    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value): string => trim((string) $value),
        );
    }
}
