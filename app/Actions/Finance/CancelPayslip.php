<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\PaymentMethod;
use App\Enums\PayslipStatus;
use App\Models\PayrollAdjustment;
use App\Models\Payslip;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

/** Voids a draft or reverses a paid payslip. Its adjustments go back to pending so a regenerated payslip picks them up. */
final class CancelPayslip
{
    public function handle(Payslip $payslip, PayslipStatus $expected, string $reason, User $actor): Payslip
    {
        return DB::transaction(function () use ($payslip, $expected, $reason, $actor): Payslip {
            FinanceLock::acquire();

            $payslip = Payslip::query()->lockForUpdate()->findOrFail($payslip->getKey());

            if ($payslip->status !== $expected) {
                abort(422, $expected === PayslipStatus::Draft ? 'Only a draft payslip can be voided.' : 'Only a paid payslip can be reversed.');
            }

            if ($payslip->status === PayslipStatus::Draft) {
                $payslip->update(['status' => PayslipStatus::Void, 'live_marker' => null, 'void_reason' => $reason]);
            } elseif ($payslip->status === PayslipStatus::Paid) {
                if ($payslip->method === PaymentMethod::Cash) {
                    CashGuard::assertOpen(today()->toDateString());
                }

                $payslip->update([
                    'status' => PayslipStatus::Reversed, 'live_marker' => null,
                    'reversed_at' => now(), 'reversed_by' => $actor->getKey(), 'reverse_reason' => $reason,
                ]);
            }

            PayrollAdjustment::query()->where('payslip_id', $payslip->getKey())->update(['payslip_id' => null, 'updated_at' => now()]);

            return $payslip;
        });
    }
}
