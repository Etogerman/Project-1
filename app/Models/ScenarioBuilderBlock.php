<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ScenarioBuilderBlock extends Model
{
    use HasFactory;

    public const TYPE_START_CONDITION = 'start_condition';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_version_id',
        'type',
        'title',
        'position_x',
        'position_y',
        'settings_payload',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position_x' => 'integer',
        'position_y' => 'integer',
        'settings_payload' => 'array',
    ];

    public function scenarioVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'scenario_builder_block_channels')
            ->withTimestamps();
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(ScenarioBuilderCondition::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function outgoingEdges(): HasMany
    {
        return $this->hasMany(ScenarioBuilderEdge::class, 'from_scenario_builder_block_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function incomingEdges(): HasMany
    {
        return $this->hasMany(ScenarioBuilderEdge::class, 'to_scenario_builder_block_id')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
