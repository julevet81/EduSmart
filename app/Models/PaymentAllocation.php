<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** How much of a payment settles which invoice. Never edited; reversing a payment stamps reversed_at. */
class PaymentAllocation extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['payment_id', 'invoice_id', 'amount', 'reversed_at'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'reversed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static function (PaymentAllocation $allocation): void {
            if (array_diff(array_keys($allocation->getDirty()), ['reversed_at', 'updated_at']) !== []) {
                throw new LogicException('Payment allocations are immutable.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Payment allocations cannot be deleted.');
        });
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
