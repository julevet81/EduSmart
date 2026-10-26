<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

final class RecordExpense
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Expense, 1: bool} the expense and whether it was newly created (same idempotency key = replay)
     */
    public function handle(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            FinanceLock::acquire();

            $key = $data['idempotency_key'] ?? null;

            if ($key !== null && ($existing = Expense::query()->where('idempotency_key', $key)->first()) !== null) {
                if ($existing->amount !== (int) $data['amount'] || $existing->expense_category_id !== (int) $data['expense_category_id']) {
                    abort(409, 'This idempotency key was already used with different data.');
                }

                return [$existing, false];
            }

            if (! ExpenseCategory::query()->whereKey($data['expense_category_id'])->where('is_active', true)->exists()) {
                abort(422, 'The expense category is inactive.');
            }

            $paidOn = $data['paid_on'] ?? today()->toDateString();

            if ($data['method'] === PaymentMethod::Cash->value) {
                CashGuard::assertOpen($paidOn);
            }

            $expense = Expense::query()->create([
                'expense_category_id' => $data['expense_category_id'],
                'description' => $data['description'],
                'supplier' => $data['supplier'] ?? null,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $paidOn,
                'status' => PaymentStatus::Valid,
                'idempotency_key' => $key,
                'note' => $data['note'] ?? null,
                'created_by' => $actor->getKey(),
            ]);

            return [$expense, true];
        });
    }
}
