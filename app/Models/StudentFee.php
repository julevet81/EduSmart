<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DiscountType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A fee plan assigned to a student, with an optional discount (siblings, scholarship...). Dates are "Y-m-d" strings. */
class StudentFee extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'student_id', 'fee_plan_id', 'discount_type', 'discount_value', 'discount_reason', 'starts_on', 'ends_on', 'is_active',
    ];

    protected function casts(): array
    {
        return ['discount_type' => DiscountType::class, 'discount_value' => 'integer', 'is_active' => 'boolean'];
    }

    /** Discount in centimes for a gross amount. Percent is floored; the result never exceeds the gross amount. */
    public function discountFor(int $gross): int
    {
        return match ($this->discount_type) {
            DiscountType::Percent => intdiv($gross * min($this->discount_value, 100), 100),
            DiscountType::Fixed => min($this->discount_value, $gross),
            null => 0,
        };
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }

    /** @return BelongsTo<FeePlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(FeePlan::class, 'fee_plan_id');
    }
}
