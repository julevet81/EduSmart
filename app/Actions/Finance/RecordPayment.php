<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Student;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use App\Support\Finance\InvoiceAllocator;
use App\Support\Finance\InvoiceBalance;
use App\Support\Sequences\Sequence;
use Illuminate\Support\Facades\DB;

/**
 * Records money received: one payment, one gap-free receipt number, and its allocations to invoices.
 * Without explicit allocations the amount settles the oldest invoices first; any remainder stays as credit.
 * Replaying the same idempotency key returns the original payment instead of charging twice.
 */
final class RecordPayment
{
    public function __construct(private readonly InvoiceAllocator $allocator) {}

    /**
     * @param  array<string, mixed>  $data  amount, method, paid_on, reference, note, guardian_id, allocations, idempotency_key
     * @return array{0: Payment, 1: bool} the payment and whether it was newly created
     */
    public function handle(Student $student, array $data, User $actor): array
    {
        return DB::transaction(function () use ($student, $data, $actor): array {
            FinanceLock::acquire();

            $amount = (int) $data['amount'];
            $key = $data['idempotency_key'] ?? null;

            if ($key !== null) {
                $existing = Payment::query()->where('idempotency_key', $key)->first();

                if ($existing !== null) {
                    if ((int) $existing->student_id !== (int) $student->getKey() || $existing->amount !== $amount) {
                        abort(409, 'This idempotency key was already used with different data.');
                    }

                    return [$existing->load('allocations'), false];
                }
            }

            $guardianId = $data['guardian_id'] ?? null;

            if ($guardianId !== null && ! $student->guardians()->whereKey($guardianId)->exists()) {
                abort(422, 'The payer is not a guardian of this student.');
            }

            $plan = isset($data['allocations'])
                ? $this->allocator->explicit((int) $student->getKey(), $data['allocations'], $amount)
                : $this->allocator->oldestFirst((int) $student->getKey(), $amount);

            $paidOn = $data['paid_on'] ?? today()->toDateString();

            if ($data['method'] === PaymentMethod::Cash->value) {
                CashGuard::assertOpen($paidOn);
            }

            $year = now()->format('Y');

            $payment = Payment::query()->create([
                'receipt_number' => Payment::formatReceipt($year, Sequence::next('receipt:'.$year)),
                'student_id' => $student->getKey(),
                'guardian_id' => $guardianId,
                'amount' => $amount,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'paid_on' => $paidOn,
                'status' => PaymentStatus::Valid,
                'idempotency_key' => $key,
                'note' => $data['note'] ?? null,
                'received_by' => $actor->getKey(),
            ]);

            foreach ($plan as $line) {
                PaymentAllocation::query()->create([
                    'payment_id' => $payment->getKey(),
                    'invoice_id' => $line['invoice_id'],
                    'amount' => $line['amount'],
                ]);

                InvoiceBalance::recalculate($line['invoice_id']);
            }

            return [$payment->load('allocations'), true];
        });
    }
}
