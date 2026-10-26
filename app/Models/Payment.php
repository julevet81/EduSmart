<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Append-only: the amount, student, receipt number and date can never change, and a payment is never deleted.
 * A mistake is corrected by REVERSING the payment (status + reason), keeping the receipt number in the books.
 */
class Payment extends Model
{
    use Auditable, BelongsToTenant;

    private const MUTABLE = ['status', 'reversed_at', 'reversed_by', 'reverse_reason', 'updated_at'];

    protected $fillable = [
        'receipt_number', 'student_id', 'guardian_id', 'amount', 'method', 'reference', 'paid_on',
        'status', 'idempotency_key', 'note', 'received_by', 'reversed_at', 'reversed_by', 'reverse_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (Payment $payment): void {
            $illegal = array_diff(array_keys($payment->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new LogicException('Payments are immutable (tried to change: '.implode(', ', $illegal).'). Reverse the payment instead.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Payments cannot be deleted. Reverse them instead.');
        });
    }

    public static function formatReceipt(string $year, int $sequence): string
    {
        return sprintf('RC-%s-%06d', $year, $sequence);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /** @return BelongsTo<Guardian, $this> */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class)->withTrashed();
    }

    /** @return HasMany<PaymentAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
