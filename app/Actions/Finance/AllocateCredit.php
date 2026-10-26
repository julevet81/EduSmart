<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Support\Finance\FinanceLock;
use App\Support\Finance\InvoiceAllocator;
use App\Support\Finance\InvoiceBalance;
use Illuminate\Support\Facades\DB;

/** Uses the unallocated part of the student's earlier payments (advances, overpayments) on their payable invoices. */
final class AllocateCredit
{
    public function __construct(private readonly InvoiceAllocator $allocator) {}

    /** @return int centimes applied */
    public function handle(Student $student): int
    {
        return DB::transaction(function () use ($student): int {
            FinanceLock::acquire();

            $remaining = Payment::query()
                ->where('student_id', $student->getKey())
                ->where('status', PaymentStatus::Valid->value)
                ->withSum(['allocations as allocated_sum' => fn ($q) => $q->whereNull('reversed_at')], 'amount')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->mapWithKeys(fn (Payment $p): array => [$p->getKey() => $p->amount - (int) $p->allocated_sum])
                ->filter(fn (int $left): bool => $left > 0)
                ->all();

            $applied = 0;
            $touched = [];

            foreach ($this->allocator->payable((int) $student->getKey()) as $invoice) {
                $due = $invoice->outstanding();

                foreach ($remaining as $paymentId => $left) {
                    if ($due <= 0) {
                        break;
                    }

                    $take = min($left, $due);

                    if ($take <= 0) {
                        continue;
                    }

                    PaymentAllocation::query()->create([
                        'payment_id' => $paymentId, 'invoice_id' => $invoice->getKey(), 'amount' => $take,
                    ]);

                    $remaining[$paymentId] -= $take;
                    $due -= $take;
                    $applied += $take;
                    $touched[$invoice->getKey()] = true;
                }
            }

            foreach (array_keys($touched) as $invoiceId) {
                InvoiceBalance::recalculate((int) $invoiceId);
            }

            return $applied;
        });
    }
}
