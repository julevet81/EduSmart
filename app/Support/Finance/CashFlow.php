<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Enums\AdjustmentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PayslipStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;

/**
 * Cash (not bank/cheque/card) entering and leaving the drawer between two dates, both included.
 * A movement counts on its own date; a later reversal counts as the opposite movement on the reversal date.
 */
final class CashFlow
{
    /** @return array{in: int, out: int} centimes */
    public static function between(string $from, string $to): array
    {
        $in = 0;
        $out = 0;

        // [query of the CASH rows, date column, amount column, statuses that were ever effective, direction]
        $sources = [
            [Payment::query(), 'paid_on', 'amount', [PaymentStatus::Valid, PaymentStatus::Reversed], 'in'],
            [Expense::query(), 'paid_on', 'amount', [PaymentStatus::Valid, PaymentStatus::Reversed], 'out'],
            [Payslip::query(), 'paid_on', 'net_amount', [PayslipStatus::Paid, PayslipStatus::Reversed], 'out'],
            [PayrollAdjustment::query()->where('kind', AdjustmentKind::Advance->value), 'effective_on', 'amount',
                [PaymentStatus::Valid, PaymentStatus::Reversed], 'out'],
        ];

        foreach ($sources as [$query, $dateColumn, $amountColumn, $statuses, $direction]) {
            $cash = $query->where('method', PaymentMethod::Cash->value);
            $values = array_map(static fn ($s): string => $s->value, $statuses);
            $reversed = end($values);

            $original = (int) (clone $cash)->whereIn('status', $values)->whereBetween($dateColumn, [$from, $to])->sum($amountColumn);
            $refund = (int) (clone $cash)->where('status', $reversed)
                ->whereDate('reversed_at', '>=', $from)->whereDate('reversed_at', '<=', $to)->sum($amountColumn);

            if ($direction === 'in') {
                $in += $original;
                $out += $refund;
            } else {
                $out += $original;
                $in += $refund;
            }
        }

        return ['in' => $in, 'out' => $out];
    }
}
