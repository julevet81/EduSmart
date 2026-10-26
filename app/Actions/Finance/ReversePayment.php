<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use App\Support\Finance\InvoiceBalance;
use Illuminate\Support\Facades\DB;

/** Cancels a payment without deleting anything: the receipt stays in the books, flagged as reversed. */
final class ReversePayment
{
    public function handle(Payment $payment, string $reason, User $actor): Payment
    {
        return DB::transaction(function () use ($payment, $reason, $actor): Payment {
            FinanceLock::acquire();

            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());

            if ($payment->status !== PaymentStatus::Valid) {
                abort(422, 'This payment is already reversed.');
            }

            if ($payment->method === PaymentMethod::Cash) {
                CashGuard::assertOpen(today()->toDateString());
            }

            $open = PaymentAllocation::query()->where('payment_id', $payment->getKey())->whereNull('reversed_at');
            $invoiceIds = (clone $open)->pluck('invoice_id')->unique()->all();

            $open->update(['reversed_at' => now()]);

            $payment->update([
                'status' => PaymentStatus::Reversed,
                'reversed_at' => now(),
                'reversed_by' => $actor->getKey(),
                'reverse_reason' => $reason,
            ]);

            foreach ($invoiceIds as $invoiceId) {
                InvoiceBalance::recalculate((int) $invoiceId);
            }

            return $payment->load('allocations');
        });
    }
}
