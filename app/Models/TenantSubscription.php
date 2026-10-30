<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One paid period of a tenant. Append-only: mistakes are voided, never edited or deleted. */
class TenantSubscription extends Model
{
    /** The only attributes that may change after creation. */
    private const MUTABLE = ['status', 'voided_at', 'void_reason', 'updated_at'];

    protected $fillable = [
        'tenant_id', 'plan_id', 'period_start', 'period_end', 'months', 'amount', 'method', 'reference',
        'paid_on', 'note', 'idempotency_key', 'status', 'voided_at', 'void_reason', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'paid_on' => 'date', 'voided_at' => 'datetime',
            'months' => 'integer', 'amount' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (TenantSubscription $row): void {
            if (array_diff(array_keys($row->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('A recorded subscription period cannot be edited; void it instead.');
            }
        });

        static::deleting(static function (): never {
            throw new LogicException('Subscription periods cannot be deleted; void them instead.');
        });
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
