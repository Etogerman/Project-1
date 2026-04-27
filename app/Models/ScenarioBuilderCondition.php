<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScenarioBuilderCondition extends Model
{
    use HasFactory;

    public const TYPE_MESSAGE_PARAMETER = 'message_parameter';

    public const MATCH_SCOPE_EXACT_KEYWORD = AutoReplyRule::MATCH_SCOPE_EXACT_KEYWORD;

    public const MATCH_SCOPE_CONTAINS_TEXT = AutoReplyRule::MATCH_SCOPE_CONTAINS_TEXT;

    public const MATCH_SCOPE_EXACT_PARAMETER = AutoReplyRule::MATCH_SCOPE_EXACT_PARAMETER;

    public const MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER = AutoReplyRule::MATCH_SCOPE_EXACT_TEXT_OR_PARAMETER;

    public const MATCH_SCOPE_ANY_INBOUND = AutoReplyRule::MATCH_SCOPE_ANY_INBOUND;

    public const VARIABLE_MESSAGE_PARAMETER = 'message_parameter';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'scenario_builder_block_id',
        'type',
        'match_operator',
        'variable',
        'value',
        'sort_order',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function builderBlock(): BelongsTo
    {
        return $this->belongsTo(ScenarioBuilderBlock::class, 'scenario_builder_block_id');
    }
}
