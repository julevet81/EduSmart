<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AdjustmentKind;
use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * advance (money handed over early, a cash movement), deduction (penalty) or bonus. Settled by the next payslip.
 * Cancelled (never deleted) while no payslip has taken it into account.
 */
class PayrollAdjustment extends Model
{
    use Auditable, BelongsToTenant;

    private const MUTABLE = ['payslip_id', 'status', 'reversed_at', 'reversed_by', 'reverse_reason', 'updated_at'];

    protected $fillable = [
        'payee_type', 'payee_id', 'kind', 'amount', 'method', 'effective_on', 'note', 'status', 'payslip_id',
        'created_by', 'reversed_at', 'reversed_by', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'payee_type' => PayeeType::class,
            'kind' => AdjustmentKind::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (PayrollAdjustment $a): void {
            $illegal = array_diff(array_keys($a->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new LogicException('Payroll adjustments are immutable (tried to change: '.implode(', ', $illegal).'). Cancel instead.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Payroll adjustments cannot be deleted. Cancel them instead.');
        });
    }
}
