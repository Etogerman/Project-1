<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRequestAttempt extends Model
{
    public const STATUS_SUCCESS = 'success';

    public const STATUS_ERROR = 'error';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'ai_request_id',
        'ai_processor_id',
        'attempt_number',
        'provider',
        'model',
        'status',
        'http_status',
        'request_body_raw',
        'response_body_raw',
        'raw_body_truncated',
        'input_tokens',
        'output_tokens',
        'thinking_tokens',
        'total_tokens',
        'estimated_cost',
        'provider_reported_cost',
        'provider_reported_currency',
        'currency',
        'cost_status',
        'error_message',
        'response_preview',
        'started_at',
        'finished_at',
        'latency_ms',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'attempt_number' => 'integer',
        'http_status' => 'integer',
        'raw_body_truncated' => 'boolean',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'thinking_tokens' => 'integer',
        'total_tokens' => 'integer',
        'estimated_cost' => 'decimal:8',
        'provider_reported_cost' => 'decimal:8',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'latency_ms' => 'integer',
    ];

    public function aiRequest(): BelongsTo
    {
        return $this->belongsTo(AiRequest::class);
    }

    public function aiProcessor(): BelongsTo
    {
        return $this->belongsTo(AiProcessor::class);
    }
}
