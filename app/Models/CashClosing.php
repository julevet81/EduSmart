<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** A signed-off count of the cash drawer. Fully immutable: the next closing starts from this counted balance. */
class CashClosing extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'closing_date', 'period_start', 'opening_balance', 'cash_in', 'cash_out', 'expected_balance',
        'counted_balance', 'difference', 'note', 'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'integer', 'cash_in' => 'integer', 'cash_out' => 'integer',
            'expected_balance' => 'integer', 'counted_balance' => 'integer', 'difference' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Cash closings are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Cash closings cannot be deleted.');
        });
    }
}
