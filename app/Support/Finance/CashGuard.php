<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Models\CashClosing;

/** Once a day is closed, no cash movement may be dated on or before it: the counted balance would silently change. */
final class CashGuard
{
    public static function lastClosing(): ?string
    {
        $date = CashClosing::query()->max('closing_date');

        return $date === null ? null : (string) $date;
    }

    /** Call inside a finance transaction (after FinanceLock::acquire) for any CASH movement dated $date. */
    public static function assertOpen(string $date): void
    {
        $last = self::lastClosing();

        if ($last !== null && $date <= $last) {
            abort(422, "The cash register is closed up to {$last}. Record the movement on a later date.");
        }
    }
}
