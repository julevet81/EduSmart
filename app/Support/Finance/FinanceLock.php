<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;

/**
 * Serializes the money-moving writes of ONE tenant (payments, reversals, invoice generation, voids).
 * Locks the tenant row, so two concurrent requests cannot both allocate the same outstanding balance
 * or reuse an idempotency key. MUST be called inside a DB transaction; released at commit/rollback.
 */
final class FinanceLock
{
    public static function acquire(): void
    {
        $tenantId = app(TenantContext::class)->require()->getKey();

        Tenant::query()->whereKey($tenantId)->lockForUpdate()->first();
    }
}
