<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

/** Cancels an invoice that nobody has paid. A paid invoice needs its payments reversed first. */
final class VoidInvoice
{
    public function handle(Invoice $invoice, string $reason, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            FinanceLock::acquire();

            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->getKey());

            if ($invoice->status === InvoiceStatus::Void) {
                abort(422, 'This invoice is already void.');
            }

            if ($invoice->paid_amount > 0) {
                abort(422, 'This invoice has payments. Reverse them first.');
            }

            $invoice->update([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'voided_by' => $actor->getKey(),
                'void_reason' => $reason,
            ]);

            return $invoice;
        });
    }
}
