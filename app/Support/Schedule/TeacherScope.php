<?php

declare(strict_types=1);

namespace App\Support\Schedule;

use App\Models\Lesson;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level rule for teachers: a user holding the "full" permission (e.g. schedule.view) sees every lesson;
 * anyone else (a teacher with only teaching.own) sees only the lessons assigned to their own teacher profile.
 */
final class TeacherScope
{
    public static function teacherIdOf(User $user): ?int
    {
        $id = Teacher::query()->where('user_id', $user->getKey())->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @param  Builder<Lesson>  $query */
    public static function restrict(Builder $query, User $user, string $fullPermission): void
    {
        if ($user->can($fullPermission)) {
            return;
        }

        $teacherId = self::teacherIdOf($user);

        $teacherId === null ? $query->whereRaw('1 = 0') : $query->where('teacher_id', $teacherId);
    }

    public static function canAccess(User $user, Lesson $lesson, string $fullPermission): bool
    {
        if ($user->can($fullPermission)) {
            return true;
        }

        $teacherId = self::teacherIdOf($user);

        return $teacherId !== null && $teacherId === (int) $lesson->teacher_id;
    }
}
