<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Models\CashClosing;
use App\Models\User;
use App\Support\Finance\CashFlow;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

/**
 * Closes the cash drawer up to a date: expected = previous counted balance + cash in - cash out since then.
 * The counted amount is what is really in the drawer; the (signed) difference is kept, never hidden.
 */
final class CloseCash
{
    /** @return array{opening_balance: int, period_start: ?string, cash_in: int, cash_out: int, expected_balance: int} */
    public function position(string $date): array
    {
        $previous = CashClosing::query()->orderByDesc('closing_date')->first();
        $from = $previous === null ? '1970-01-01' : date('Y-m-d', strtotime($previous->closing_date.' +1 day'));
        $flow = CashFlow::between($from, $date);
        $opening = $previous?->counted_balance ?? 0;

        return [
            'opening_balance' => $opening,
            'period_start' => $previous === null ? null : $from,
            'cash_in' => $flow['in'],
            'cash_out' => $flow['out'],
            'expected_balance' => $opening + $flow['in'] - $flow['out'],
        ];
    }

    public function handle(string $date, int $counted, ?string $note, User $actor, int $firstOpening = 0): CashClosing
    {
        if ($date > today()->toDateString()) {
            abort(422, 'The cash register cannot be closed for a future date.');
        }

        return DB::transaction(function () use ($date, $counted, $note, $actor, $firstOpening): CashClosing {
            FinanceLock::acquire();

            $last = CashGuard::lastClosing();

            if ($last !== null && $date <= $last) {
                abort(422, "The cash register is already closed up to {$last}.");
            }

            $position = $this->position($date);

            if ($last === null) {
                $position['opening_balance'] = $firstOpening;
                $position['expected_balance'] += $firstOpening;
            }

            return CashClosing::query()->create($position + [
                'closing_date' => $date,
                'counted_balance' => $counted,
                'difference' => $counted - $position['expected_balance'],
                'note' => $note,
                'closed_by' => $actor->getKey(),
            ]);
        });
    }
}
