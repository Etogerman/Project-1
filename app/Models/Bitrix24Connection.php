<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bitrix24Connection extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_NEEDS_REINSTALL = 'needs_reinstall';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'portal_domain',
        'application_name',
        'client_id',
        'member_id',
        'application_token',
        'status',
        'access_token_encrypted',
        'refresh_token_encrypted',
        'access_token_expires_at',
        'scope',
        'client_endpoint',
        'server_endpoint',
        'install_payload',
        'installed_at',
        'last_refreshed_at',
        'last_install_callback_at',
        'last_events_callback_at',
        'last_openlines_callback_at',
        'last_error_at',
        'last_error_message',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'access_token_encrypted',
        'refresh_token_encrypted',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'access_token_encrypted' => 'encrypted',
        'refresh_token_encrypted' => 'encrypted',
        'access_token_expires_at' => 'datetime',
        'scope' => 'array',
        'install_payload' => 'array',
        'installed_at' => 'datetime',
        'last_refreshed_at' => 'datetime',
        'last_install_callback_at' => 'datetime',
        'last_events_callback_at' => 'datetime',
        'last_openlines_callback_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    public function webhookEvents(): HasMany
    {
        return $this->hasMany(Bitrix24WebhookEvent::class, 'connection_id');
    }

    public function syncLogs(): HasMany
    {
        return $this->hasMany(Bitrix24SyncLog::class, 'connection_id');
    }
}
