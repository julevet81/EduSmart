<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Opaque, single-use refresh token (stored as a hash). Not tenant-scoped: it is read before anybody is authenticated. */
class RefreshToken extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'tenant_id', 'token_hash', 'family_id', 'access_token_id',
        'expires_at', 'family_expires_at', 'used_at', 'revoked_at', 'ip_address', 'user_agent',
    ];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'family_expires_at' => 'datetime', 'used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
