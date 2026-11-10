<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'refresh_token',
    'refresh_token_last_used_at',
    'scopes',
    'ip_whitelist',
    'is_active',
    'notes',
])]
class Beds24Credential extends Model
{
    protected $table = 'beds24_credentials';

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'refresh_token_last_used_at' => 'datetime',
            'scopes' => 'array',
            'ip_whitelist' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Beds24SyncLog, $this>
     */
    public function syncLogs(): HasMany
    {
        return $this->hasMany(Beds24SyncLog::class, 'credential_id');
    }
}
