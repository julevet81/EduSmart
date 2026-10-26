<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Append-only: a mistake is corrected by REVERSING the expense, never by editing or deleting it. */
class Expense extends Model
{
    use Auditable, BelongsToTenant;

    private const MUTABLE = ['status', 'reversed_at', 'reversed_by', 'reverse_reason', 'updated_at'];

    protected $fillable = [
        'expense_category_id', 'description', 'supplier', 'amount', 'method', 'reference', 'paid_on', 'status',
        'idempotency_key', 'note', 'created_by', 'reversed_at', 'reversed_by', 'reverse_reason',
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
        static::updating(static function (Expense $expense): void {
            $illegal = array_diff(array_keys($expense->getDirty()), self::MUTABLE);

            if ($illegal !== []) {
                throw new LogicException('Expenses are immutable (tried to change: '.implode(', ', $illegal).'). Reverse the expense instead.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Expenses cannot be deleted. Reverse them instead.');
        });
    }

    /** @return BelongsTo<ExpenseCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }
}
