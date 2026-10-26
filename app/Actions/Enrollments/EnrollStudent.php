<?php

declare(strict_types=1);

namespace App\Actions\Enrollments;

use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/** Enrolls a student in a group, enforcing: active group, capacity, one active enrollment per year. */
final class EnrollStudent
{
    public function handle(Student $student, Group $group, ?CarbonInterface $on = null, ?string $notes = null): Enrollment
    {
        try {
            return DB::transaction(function () use ($student, $group, $on, $notes): Enrollment {
                // Lock the group row so two simultaneous enrollments cannot both take the last seat.
                $group = Group::query()->whereKey($group->getKey())->lockForUpdate()->firstOrFail();

                if (! $group->is_active) {
                    abort(422, 'This group is not active.');
                }

                if ($group->capacity !== null && $group->activeEnrollments()->count() >= $group->capacity) {
                    abort(422, 'This group is full.');
                }

                $already = Enrollment::query()
                    ->where('student_id', $student->getKey())
                    ->where('academic_year_id', $group->academic_year_id)
                    ->where('status', EnrollmentStatus::Active->value)
                    ->exists();

                if ($already) {
                    abort(422, 'The student already has an active enrollment in this academic year.');
                }

                return Enrollment::query()->create([
                    'student_id' => $student->getKey(),
                    'group_id' => $group->getKey(),
                    'academic_year_id' => $group->academic_year_id,
                    'status' => EnrollmentStatus::Active,
                    'enrolled_on' => ($on ?? now())->toDateString(),
                    'notes' => $notes,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            abort(422, 'The student already has an active enrollment in this academic year.');
        }
    }
}
