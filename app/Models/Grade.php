<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GradeStatus;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** score is stored in hundredths of a point; null when the student was absent. */
class Grade extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = ['exam_id', 'student_id', 'status', 'score', 'comment', 'entered_by'];

    protected function casts(): array
    {
        return ['status' => GradeStatus::class, 'score' => 'integer'];
    }

    /** @return BelongsTo<Exam, $this> */
    public function exam(): BelongsTo
    {
        return $this->belongsTo(Exam::class);
    }

    /** @return BelongsTo<Student, $this> */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class)->withTrashed();
    }
}
