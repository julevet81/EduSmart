<?php

declare(strict_types=1);

namespace App\Support\Sequences;

use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free per-tenant counters (student numbers now, receipt/invoice numbers in the finance phase).
 * Call inside the same DB transaction as the record that consumes the number, so a rollback frees it.
 */
final class Sequence
{
    public static function next(string $name): int
    {
        $tenantId = app(TenantContext::class)->require()->getKey();

        try {
            return self::increment($tenantId, $name);
        } catch (UniqueConstraintViolationException) {
            // Two requests created the very first row at once: the loser retries against the existing row.
            return self::increment($tenantId, $name);
        }
    }

    private static function increment(int $tenantId, string $name): int
    {
        return DB::transaction(static function () use ($tenantId, $name): int {
            $row = DB::table('sequences')->where('tenant_id', $tenantId)->where('name', $name)->lockForUpdate()->first();

            if ($row === null) {
                DB::table('sequences')->insert(['tenant_id' => $tenantId, 'name' => $name, 'value' => 1]);

                return 1;
            }

            $next = (int) $row->value + 1;
            DB::table('sequences')->where('id', $row->id)->update(['value' => $next]);

            return $next;
        });
    }
}
