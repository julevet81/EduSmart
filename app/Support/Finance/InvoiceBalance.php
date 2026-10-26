<?php

declare(strict_types=1);

namespace App\Support\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\PaymentAllocation;

/** paid_amount and status of an invoice are a cache of its valid (non-reversed) allocations. Call inside a transaction. */
final class InvoiceBalance
{
    public static function recalculate(int $invoiceId): Invoice
    {
        $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoiceId);

        if ($invoice->status === InvoiceStatus::Void) {
            return $invoice;
        }

        $paid = (int) PaymentAllocation::query()->where('invoice_id', $invoiceId)->whereNull('reversed_at')->sum('amount');

        $invoice->update([
            'paid_amount' => $paid,
            'status' => match (true) {
                $paid <= 0 => InvoiceStatus::Open,
                $paid >= $invoice->total_amount => InvoiceStatus::Paid,
                default => InvoiceStatus::PartiallyPaid,
            },
        ]);

        return $invoice;
    }
}
