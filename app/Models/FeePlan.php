<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\BillingMode;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A price list entry. Changing it never rewrites issued invoices (they keep their own unit price). */
class FeePlan extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['academic_year_id', 'subject_id', 'name', 'billing_mode', 'amount', 'due_day', 'bill_absences', 'is_active'];

    protected function casts(): array
    {
        return [
            'billing_mode' => BillingMode::class,
            'amount' => 'integer',
            'due_day' => 'integer',
            'bill_absences' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }
}
