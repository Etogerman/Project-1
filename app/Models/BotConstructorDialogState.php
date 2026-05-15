<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotConstructorDialogState extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'dialog_id',
        'current_block_id',
        'last_execution_id',
    ];

    public function dialog(): BelongsTo
    {
        return $this->belongsTo(Dialog::class);
    }

    public function currentBlock(): BelongsTo
    {
        return $this->belongsTo(BotConstructorBlock::class, 'current_block_id');
    }

    public function lastExecution(): BelongsTo
    {
        return $this->belongsTo(BotConstructorExecution::class, 'last_execution_id');
    }
}
