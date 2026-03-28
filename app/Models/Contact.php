<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contact extends Model
{
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'is_auto_reply_enabled',
        'assigned_user_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'is_auto_reply_enabled' => 'boolean',
    ];

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function identities(): HasMany
    {
        return $this->hasMany(ContactIdentity::class);
    }

    public function primaryIdentity(): HasOne
    {
        return $this->hasOne(ContactIdentity::class)->oldestOfMany();
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function isAssigned(): bool
    {
        return filled($this->assigned_user_id);
    }

    public function isAutoReplyEnabled(): bool
    {
        return (bool) $this->is_auto_reply_enabled;
    }

    public function isAssignedTo(User $user): bool
    {
        return (int) $this->assigned_user_id === $user->id;
    }

    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                if (filled($this->name)) {
                    return (string) $this->name;
                }

                $identity = $this->relationLoaded('primaryIdentity')
                    ? $this->primaryIdentity
                    : $this->primaryIdentity()->first();

                if (filled($identity?->external_username)) {
                    return '@'.ltrim((string) $identity->external_username, '@');
                }

                if (filled($identity?->external_user_id)) {
                    return (string) $identity->external_user_id;
                }

                return sprintf('Контакт #%d', $this->id);
            },
        );
    }
}
