<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AbsenceType;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Absence extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['lesson_id', 'student_id', 'type', 'reason', 'is_justified', 'justification', 'justified_at'];

    protected function casts(): array
    {
        return ['type' => AbsenceType::class, 'is_justified' => 'boolean', 'justified_at' => 'datetime'];
    }

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}
