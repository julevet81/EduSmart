<?php

declare(strict_types=1);

namespace App\Support\Schedule;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Serializes every timetable write of ONE tenant (MySQL has no exclusion constraints).
 * Locks the tenant row, so two concurrent requests can never both pass the conflict check
 * and then both insert. Other tenants are not affected. MUST be called inside a DB transaction;
 * the lock is released at commit/rollback.
 */
final class ScheduleLock
{
    public static function acquire(): void
    {
        $tenantId = app(TenantContext::class)->require()->getKey();

        Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();
    }
}
