<?php

declare(strict_types=1);

namespace App\Actions\Schedule;

use App\Enums\LessonStatus;
use App\Models\Absence;
use App\Models\Lesson;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;

/**
 * Attendance = the full list of exceptions of ONE lesson. Everyone not listed is present.
 * Submitting again replaces the previous list (justifications of kept students are preserved).
 * Taking attendance marks the lesson as completed.
 */
final class RecordAttendance
{
    /** @param  list<array{student_id: int, type: string, reason?: string|null}>  $absences */
    public function handle(Lesson $lesson, array $absences, User $actor): Lesson
    {
        if ($lesson->date > today()->toDateString()) {
            abort(422, 'Attendance cannot be recorded for a future lesson.');
        }

        $ids = array_values(array_unique(array_column($absences, 'student_id')));

        if ($ids !== []) {
            $enrolled = $lesson->enrolledStudentIds()->whereIn('student_id', $ids)->distinct()->pluck('student_id')->all();

            if (count($enrolled) !== count($ids)) {
                abort(422, 'Some students are not enrolled in this group on the lesson date.');
            }
        }

        return DB::transaction(function () use ($lesson, $absences, $actor): Lesson {
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($lesson->getKey());

            if ($lesson->status === LessonStatus::Cancelled) {
                abort(422, 'A cancelled lesson has no attendance.');
            }

            $existing = Absence::query()->where('lesson_id', $lesson->getKey())->get()->keyBy('student_id');
            $incoming = collect($absences)->keyBy('student_id');

            foreach ($existing as $studentId => $absence) {
                if (! $incoming->has($studentId)) {
                    $absence->delete();
                }
            }

            foreach ($incoming as $studentId => $row) {
                $values = ['type' => $row['type'], 'reason' => $row['reason'] ?? null];
                $current = $existing->get($studentId);

                $current === null
                    ? Absence::query()->create($values + ['lesson_id' => $lesson->getKey(), 'student_id' => $studentId])
                    : $current->update($values);
            }

            $lesson->update([
                'status' => LessonStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $actor->getKey(),
            ]);

            AuditRecorder::event('lesson.attendance_recorded', [
                'lesson_id' => $lesson->getKey(), 'absences' => $incoming->count(),
            ]);

            return $lesson;
        });
    }
}
