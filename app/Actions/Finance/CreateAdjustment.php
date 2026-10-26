<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\AdjustmentKind;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use App\Support\Finance\Payee;
use Illuminate\Support\Facades\DB;

final class CreateAdjustment
{
    /** @param  array<string, mixed>  $data */
    public function handle(array $data, User $actor): PayrollAdjustment
    {
        $type = PayeeType::from($data['payee_type']);
        $kind = AdjustmentKind::from($data['kind']);
        $date = $data['effective_on'] ?? today()->toDateString();

        if ($kind === AdjustmentKind::Advance && empty($data['method'])) {
            abort(422, 'An advance needs the payment method it was handed over with.');
        }

        return DB::transaction(function () use ($data, $actor, $type, $kind, $date): PayrollAdjustment {
            FinanceLock::acquire();

            if (! Payee::exists($type, (int) $data['payee_id'])) {
                abort(422, 'The payee does not exist.');
            }

            // Only an advance moves money today; deductions and bonuses are settled by the payslip.
            if ($kind === AdjustmentKind::Advance && $data['method'] === PaymentMethod::Cash->value) {
                CashGuard::assertOpen($date);
            }

            return PayrollAdjustment::query()->create([
                'payee_type' => $type,
                'payee_id' => $data['payee_id'],
                'kind' => $kind,
                'amount' => $data['amount'],
                'method' => $kind === AdjustmentKind::Advance ? $data['method'] : null,
                'effective_on' => $date,
                'note' => $data['note'] ?? null,
                'status' => PaymentStatus::Valid,
                'created_by' => $actor->getKey(),
            ]);
        });
    }
}
