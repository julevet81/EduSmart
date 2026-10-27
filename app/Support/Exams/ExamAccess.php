<?php

declare(strict_types=1);

namespace App\Support\Exams;

use App\Models\Exam;
use App\Models\Lesson;
use App\Models\ScheduleSlot;
use App\Models\User;
use App\Support\Schedule\TeacherScope;

/** Row-level rule: holders of the "full" permission reach every exam, a teacher only the exams assigned to them. */
final class ExamAccess
{
    public static function find(User $user, string $id, string $fullPermission): Exam
    {
        $exam = Exam::query()->findOrFail($id);

        if ($user->can($fullPermission)) {
            return $exam;
        }

        $teacherId = TeacherScope::teacherIdOf($user);

        if ($teacherId === null || $teacherId !== (int) $exam->teacher_id) {
            abort(404);
        }

        return $exam;
    }

    /** A teacher may only set exams in groups and subjects they actually teach (a slot or a lesson exists). */
    public static function teaches(int $teacherId, int $groupId, int $subjectId): bool
    {
        $keys = ['teacher_id' => $teacherId, 'group_id' => $groupId, 'subject_id' => $subjectId];

        return ScheduleSlot::query()->where($keys)->exists() || Lesson::query()->where($keys)->exists();
    }
}
