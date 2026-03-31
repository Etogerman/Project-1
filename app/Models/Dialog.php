<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dialog extends Model
{
    use HasFactory;

    public const PHONE_CONFIRMED_VIA_PHONE_CAPTURE = 'phone_capture';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'channel_id',
        'current_contact_identity_id',
        'external_chat_id',
        'confirmed_phone_raw',
        'confirmed_phone_normalized',
        'phone_confirmed_at',
        'phone_confirmed_via',
        'last_message_at',
        'last_inbound_at',
        'last_outbound_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'phone_confirmed_at' => 'datetime',
        'last_message_at' => 'datetime',
        'last_inbound_at' => 'datetime',
        'last_outbound_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function currentContactIdentity(): BelongsTo
    {
        return $this->belongsTo(ContactIdentity::class, 'current_contact_identity_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }
}
