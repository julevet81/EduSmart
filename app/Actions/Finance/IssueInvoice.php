<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\Student;
use App\Models\User;
use App\Support\Finance\FinanceLock;
use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;

/** One-off invoice (registration fee, books, uniform...), outside the fee plans. */
final class IssueInvoice
{
    /** @param  array<string, mixed>  $data  description, quantity, unit_price, discount_amount, due_date */
    public function handle(Student $student, array $data, User $actor): Invoice
    {
        $quantity = (int) ($data['quantity'] ?? 1);
        $unit = (int) $data['unit_price'];
        $gross = $quantity * $unit;
        $discount = (int) ($data['discount_amount'] ?? 0);

        if ($discount >= $gross) {
            abort(422, 'The discount must be lower than the amount.');
        }

        return DB::transaction(function () use ($student, $data, $actor, $quantity, $unit, $discount, $gross): Invoice {
            FinanceLock::acquire();

            $year = now()->format('Y');

            return Invoice::query()->create([
                'number' => Invoice::formatNumber($year, Sequence::next('invoice:'.$year)),
                'student_id' => $student->getKey(),
                'description' => $data['description'],
                'issue_date' => today()->toDateString(),
                'due_date' => $data['due_date'],
                'quantity' => $quantity,
                'unit_price' => $unit,
                'discount_amount' => $discount,
                'total_amount' => $gross - $discount,
                'paid_amount' => 0,
                'status' => InvoiceStatus::Open,
                'created_by' => $actor->getKey(),
            ]);
        });
    }
}
