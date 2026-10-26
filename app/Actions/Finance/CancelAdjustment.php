<?php

declare(strict_types=1);

namespace App\Actions\Finance;

use App\Enums\AdjustmentKind;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\PayrollAdjustment;
use App\Models\User;
use App\Support\Finance\CashGuard;
use App\Support\Finance\FinanceLock;
use Illuminate\Support\Facades\DB;

final class CancelAdjustment
{
    public function handle(PayrollAdjustment $adjustment, string $reason, User $actor): PayrollAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $actor): PayrollAdjustment {
            FinanceLock::acquire();

            $adjustment = PayrollAdjustment::query()->lockForUpdate()->findOrFail($adjustment->getKey());

            if ($adjustment->status !== PaymentStatus::Valid) {
                abort(422, 'This adjustment is already cancelled.');
            }

            if ($adjustment->payslip_id !== null) {
                abort(422, 'A payslip already took this adjustment into account. Void or reverse the payslip first.');
            }

            if ($adjustment->kind === AdjustmentKind::Advance && $adjustment->method === PaymentMethod::Cash) {
                CashGuard::assertOpen(today()->toDateString());
            }

            $adjustment->update([
                'status' => PaymentStatus::Reversed, 'reversed_at' => now(), 'reversed_by' => $actor->getKey(), 'reverse_reason' => $reason,
            ]);

            return $adjustment;
        });
    }
}
