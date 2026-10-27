<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExamKind;
use App\Support\Audit\Auditable;
use App\Support\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** "date" is a plain "Y-m-d" string. A locked exam accepts no more changes to itself or its grades. */
class Exam extends Model
{
    use Auditable, BelongsToTenant;

    protected $fillable = [
        'academic_year_id', 'term_id', 'group_id', 'subject_id', 'teacher_id', 'name', 'kind', 'date',
        'max_score', 'coefficient', 'is_locked', 'locked_at', 'locked_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ExamKind::class,
            'max_score' => 'integer',
            'coefficient' => 'integer',
            'is_locked' => 'boolean',
            'locked_at' => 'datetime',
        ];
    }

    /**
     * Students who were enrolled in the exam's group ON the exam date (history-aware, like attendance).
     *
     * @return Builder<Enrollment>
     */
    public function enrolledStudentIds(): Builder
    {
        return Enrollment::query()
            ->select('student_id')
            ->where('group_id', $this->group_id)
            ->whereDate('enrolled_on', '<=', $this->date)
            ->where(fn (Builder $q) => $q->whereNull('ended_on')->orWhereDate('ended_on', '>=', $this->date));
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @return BelongsTo<Subject, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /** @return BelongsTo<Term, $this> */
    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    /** @return BelongsTo<Teacher, $this> */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class)->withTrashed();
    }

    /** @return HasMany<Grade, $this> */
    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }
}
