<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A support login-as session. Never deleted; only closed. */
class ImpersonationSession extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'can_write' => 'boolean',
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(static function (): never {
            throw new LogicException('Impersonation sessions cannot be deleted.');
        });
    }

    public function isActive(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }

    /** @return array<string, mixed> */
    public function present(?PlatformAdmin $admin = null): array
    {
        return [
            'id' => $this->id, 'user_id' => $this->user_id, 'platform_admin_id' => $this->platform_admin_id,
            'by' => $admin?->name, 'reason' => $this->reason, 'can_write' => $this->can_write,
            'started_at' => $this->started_at->toIso8601String(), 'expires_at' => $this->expires_at->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(), 'ended_by' => $this->ended_by, 'active' => $this->isActive(),
        ];
    }
}
