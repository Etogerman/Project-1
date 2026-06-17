<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactEmail extends Model
{
    use HasFactory;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_V3_CAPTURE = 'v3_capture';

    public const SOURCE_BITRIX24 = 'bitrix24';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'email_raw',
        'email_normalized',
        'source',
        'is_primary',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public static function sourceLabel(?string $source): string
    {
        return match ($source) {
            self::SOURCE_MANUAL => 'Вручную',
            self::SOURCE_V3_CAPTURE => 'Сценарий',
            self::SOURCE_BITRIX24 => 'Битрикс24',
            default => 'Неизвестно',
        };
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
