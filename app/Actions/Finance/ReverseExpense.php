<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Expense;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

final class ReverseExpense
{
    public function handle(Expense $expense, string $reason, User $actor): Expense
    {
        return DB::transaction(function () use ($expense, $reason, $actor): Expense {
            FinanceLock::acquire();

            $expense = Expense::query()->lockForUpdate()->findOrFail($expense->getKey());

            if ($expense->status !== PaymentStatus::Valid) {
                abort(422, 'This expense is already reversed.');
            }

            if ($expense->method === PaymentMethod::Cash) {
                CashGuard::assertOpen(today()->toDateString());
            }

            $expense->update([
                'status' => PaymentStatus::Reversed, 'reversed_at' => now(), 'reversed_by' => $actor->getKey(), 'reverse_reason' => $reason,
            ]);

            return $expense;
        });
    }
}
