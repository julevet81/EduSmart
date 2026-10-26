<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Append-only. Corrections are new rows, never edits.
 * (Add DB-level protection in production: revoke UPDATE/DELETE on this table from the app role.)
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit logs are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit logs cannot be deleted.');
        });
    }

    /** @param  Builder<AuditLog>  $query */
    public function scopeForTenant(Builder $query, int $tenantId): void
    {
        $query->where('tenant_id', $tenantId);
    }
}
