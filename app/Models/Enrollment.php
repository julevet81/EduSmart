<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EnrollmentStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['student_id', 'group_id', 'academic_year_id', 'status', 'enrolled_on', 'ended_on', 'notes'];

    protected function casts(): array
    {
        return ['status' => EnrollmentStatus::class, 'enrolled_on' => 'date', 'ended_on' => 'date'];
    }

    protected static function booted(): void
    {
        // Keeps the "one active enrollment per student per year" unique index in sync with the status.
        static::saving(static function (Enrollment $e): void {
            $e->active_marker = $e->status === EnrollmentStatus::Active ? 1 : null;
        });
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<AcademicYear, $this> */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
