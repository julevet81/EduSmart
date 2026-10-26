<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Append-only: once issued, only the payment state and the void fields may change.
 * To correct a mistake, void the invoice and issue a new one. Dates are "Y-m-d" strings.
 */
class Invoice extends Model
{
    use Auditable, BelongsToTenant;

    private const MUTABLE = ['status', 'paid_amount', 'voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'number', 'student_id', 'student_fee_id', 'fee_plan_id', 'academic_year_id', 'description',
        'period_key', 'period_start', 'period_end', 'issue_date', 'due_date',
        'quantity', 'unit_price', 'discount_amount', 'total_amount', 'paid_amount',
        'status', 'voided_at', 'voided_by', 'void_reason', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'quantity' => 'integer',
            'unit_price' => 'integer',
            'discount_amount' => 'integer',
            'total_amount' => 'integer',
            'paid_amount' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Invoice $invoice): void {
            $illegal = array_diff(array_keys($invoice->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new LogicException('Issued invoices are immutable (tried to change: '.implode(', ', $illegal).'). Void and reissue instead.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Invoices cannot be deleted. Void them instead.');
        });
    }

    public static function formatNumber(string $year, int $sequence): string
    {
        return sprintf('INV-%s-%06d', $year, $sequence);
    }

    public function outstanding(): int
    {
        return $this->status === InvoiceStatus::Void ? 0 : max($this->total_amount - $this->paid_amount, 0);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, [InvoiceStatus::Open, InvoiceStatus::PartiallyPaid], true)
            && $this->due_date < today()->toDateString();
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
