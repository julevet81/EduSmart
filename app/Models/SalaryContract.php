<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContractType;
use App\Enums\PayeeType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** monthly = fixed amount per month; per_hour / per_lesson = rate applied to completed lessons (teachers only). Dates are "Y-m-d" strings. */
class SalaryContract extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['payee_type', 'payee_id', 'type', 'amount', 'starts_on', 'ends_on', 'is_active'];

    protected function casts(): array
    {
        return [
            'payee_type' => PayeeType::class,
            'type' => ContractType::class,
            'amount' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
