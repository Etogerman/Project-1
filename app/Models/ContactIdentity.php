<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactIdentity extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'contact_id',
        'channel_id',
        'platform',
        'external_user_id',
        'display_name',
        'external_username',
        'avatar_path',
        'avatar_updated_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'avatar_updated_at' => 'datetime',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function currentDialogs(): HasMany
    {
        return $this->hasMany(Dialog::class, 'current_contact_identity_id');
    }
}
