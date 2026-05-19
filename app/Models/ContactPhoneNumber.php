<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContactPhoneNumber extends Model
{
    use HasFactory;

    public const SOURCE_TELEGRAM_CONTACT_SHARE = 'telegram_contact_share';

    public const SOURCE_MAX_CONTACT_SHARE = 'max_contact_share';

    public const SOURCE_V3_CAPTURE = 'v3_capture';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'phone_raw',
        'phone_normalized',
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

    public function maskedNumber(): string
    {
        $normalized = $this->phone_normalized;
        $lastFour = mb_substr($normalized, -4);
        $prefix = mb_substr($normalized, 0, max(0, mb_strlen($normalized) - 4));

        $maskedPrefix = preg_replace('/\d/u', '*', $prefix) ?? $prefix;

        return $maskedPrefix.$lastFour;
    }
}
