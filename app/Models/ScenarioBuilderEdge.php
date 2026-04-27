<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioBuilderEdge extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_version_id',
        'from_scenario_builder_block_id',
        'to_scenario_builder_block_id',
        'to_runtime_block_id',
        'condition_payload',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'condition_payload' => 'array',
        'sort_order' => 'integer',
    ];

    public function scenarioVersion(): BelongsTo
    {
        return $this->belongsTo(ScenarioVersion::class);
    }

    public function fromBuilderBlock(): BelongsTo
    {
        return $this->belongsTo(ScenarioBuilderBlock::class, 'from_scenario_builder_block_id');
    }

    public function toBuilderBlock(): BelongsTo
    {
        return $this->belongsTo(ScenarioBuilderBlock::class, 'to_scenario_builder_block_id');
    }
}
