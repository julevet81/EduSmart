<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/** Decides how an amount is split over invoices. Locks the invoice rows it reads: call inside a transaction. */
final class InvoiceAllocator
{
    /** @return Collection<int, Invoice> payable invoices of the student, oldest due date first */
    public function payable(int $studentId): Collection
    {
        return Invoice::query()
            ->where('student_id', $studentId)
            ->whereIn('status', [InvoiceStatus::Open->value, InvoiceStatus::PartiallyPaid->value])
            ->orderBy('due_date')->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @return list<array{invoice_id: int, amount: int}> */
    public function oldestFirst(int $studentId, int $available): array
    {
        $plan = [];

        foreach ($this->payable($studentId) as $invoice) {
            if ($available <= 0) {
                break;
            }

            $take = min($available, $invoice->outstanding());

            if ($take > 0) {
                $plan[] = ['invoice_id' => (int) $invoice->getKey(), 'amount' => $take];
                $available -= $take;
            }
        }

        return $plan;
    }

    /**
     * @param  list<array{invoice_id: int, amount: int}>  $allocations
     * @return list<array{invoice_id: int, amount: int}>
     */
    public function explicit(int $studentId, array $allocations, int $available): array
    {
        $invoices = Invoice::query()
            ->where('student_id', $studentId)
            ->whereIn('id', array_column($allocations, 'invoice_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $sum = 0;
        $plan = [];

        foreach ($allocations as $row) {
            $invoice = $invoices->get($row['invoice_id']);

            if ($invoice === null) {
                abort(422, 'An invoice does not belong to this student.');
            }

            if (! in_array($invoice->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true)) {
                abort(422, "Invoice {$invoice->number} cannot receive a payment.");
            }

            $amount = (int) $row['amount'];

            if ($amount > $invoice->outstanding()) {
                abort(422, "The amount exceeds what is still due on invoice {$invoice->number}.");
            }

            $sum += $amount;
            $plan[] = ['invoice_id' => (int) $invoice->getKey(), 'amount' => $amount];
        }

        if ($sum > $available) {
            abort(422, 'The allocations exceed the payment amount.');
        }

        return $plan;
    }
}
