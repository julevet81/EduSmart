<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PayslipStatus;
use App\Models\Payslip;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

final class PayPayslip
{
    public function handle(Payslip $payslip, PaymentMethod $method, string $paidOn, User $actor): Payslip
    {
        return DB::transaction(function () use ($payslip, $method, $paidOn, $actor): Payslip {
            FinanceLock::acquire();

            $payslip = Payslip::query()->lockForUpdate()->findOrFail($payslip->getKey());

            if ($payslip->status !== PayslipStatus::Draft) {
                abort(422, 'Only a draft payslip can be paid.');
            }

            if ($method === PaymentMethod::Cash) {
                CashGuard::assertOpen($paidOn);
            }

            $payslip->update([
                'status' => PayslipStatus::Paid, 'method' => $method, 'paid_on' => $paidOn, 'paid_by' => $actor->getKey(),
            ]);

            return $payslip;
        });
    }
}
