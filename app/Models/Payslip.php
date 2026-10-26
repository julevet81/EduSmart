<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PayeeType;
use App\Enums\PaymentMethod;
use App\Enums\PayslipStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Append-only amounts: only the payment/void/reversal state can change after generation. */
class Payslip extends Model
{
    use Auditable, BelongsToTenant;

    private const MUTABLE = [
        'status', 'live_marker', 'method', 'paid_on', 'paid_by', 'reversed_at', 'reversed_by', 'reverse_reason', 'void_reason', 'updated_at',
    ];

    protected $fillable = [
        'number', 'payee_type', 'payee_id', 'month', 'fixed_amount', 'lessons_count', 'lessons_minutes', 'variable_amount',
        'bonus_amount', 'deduction_amount', 'advance_amount', 'net_amount', 'status', 'live_marker', 'method', 'paid_on',
        'created_by', 'paid_by', 'reversed_at', 'reversed_by', 'reverse_reason', 'void_reason',
    ];

    protected function casts(): array
    {
        return [
            'payee_type' => PayeeType::class,
            'status' => PayslipStatus::class,
            'method' => PaymentMethod::class,
            'fixed_amount' => 'integer', 'variable_amount' => 'integer', 'bonus_amount' => 'integer',
            'deduction_amount' => 'integer', 'advance_amount' => 'integer', 'net_amount' => 'integer',
            'lessons_count' => 'integer', 'lessons_minutes' => 'integer',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Payslip $p): void {
            $illegal = array_diff(array_keys($p->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new LogicException('Payslips are immutable (tried to change: '.implode(', ', $illegal).'). Void or reverse and regenerate.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Payslips cannot be deleted. Void or reverse them instead.');
        });
    }

    public static function formatNumber(string $year, int $sequence): string
    {
        return sprintf('PS-%s-%06d', $year, $sequence);
    }

    /** @return HasMany<PayrollAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(PayrollAdjustment::class);
    }
}
