<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChannelConnectionCheckRun extends Model
{
    use HasFactory;

    public const STATUS_STARTED = 'started';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'started_at',
        'finished_at',
        'status',
        'processed_count',
        'success_count',
        'failure_count',
        'duration_ms',
        'last_error_code',
        'last_error_message',
        'app_rev',
        'environment',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'processed_count' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function hasFreshFinishedAt(int $freshForMinutes): bool
    {
        return $this->finished_at !== null
            && $this->finished_at->greaterThanOrEqualTo(now()->subMinutes($freshForMinutes));
    }
}
