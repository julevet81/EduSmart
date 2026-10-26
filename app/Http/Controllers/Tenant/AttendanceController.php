<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Schedule\RecordAttendance;
use App\Enums\AbsenceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Models\Absence;
use App\Models\Lesson;
use App\Models\Student;
use App\Support\Schedule\TeacherScope;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Roster and recording of attendance for one lesson. */
final class AttendanceController extends Controller
{
    private const WITH = ['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name', 'room:id,name'];

    /** Everyone enrolled on the lesson date, "present" unless an absence row exists. */
    public function show(Request $request, string $id): JsonResponse
    {
        $lesson = $this->accessibleLesson($request, $id, 'attendance.view');

        $students = Student::query()
            ->select(['id', 'registration_number', 'first_name', 'last_name', 'first_name_latin', 'last_name_latin'])
            ->whereIn('id', $lesson->enrolledStudentIds())
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        $absences = Absence::query()->where('lesson_id', $lesson->getKey())->get()->keyBy('student_id');

        return response()->json([
            'lesson' => new LessonResource($lesson->load(self::WITH)),
            'data' => $students->map(fn (Student $s): array => [
                'student' => $s->only(['id', 'registration_number', 'first_name', 'last_name', 'first_name_latin', 'last_name_latin']),
                'status' => $absences->get($s->getKey())?->type->value ?? 'present',
                'reason' => $absences->get($s->getKey())?->reason,
                'is_justified' => (bool) $absences->get($s->getKey())?->is_justified,
                'absence_id' => $absences->get($s->getKey())?->getKey(),
            ])->values(),
        ]);
    }

    /** Replaces the list of absences/lateness of the lesson and marks it completed. */
    public function record(Request $request, RecordAttendance $record, string $id): JsonResponse
    {
        $data = $request->validate([
            'absences' => ['present', 'array', 'max:500'],
            'absences.*.student_id' => ['required', 'integer', 'distinct', TenantRules::exists('students')],
            'absences.*.type' => ['required', Rule::enum(AbsenceType::class)],
            'absences.*.reason' => ['nullable', 'string', 'max:255'],
        ]);

        $lesson = $this->accessibleLesson($request, $id, 'attendance.record');
        $lesson = $record->handle($lesson, $data['absences'], $request->user());

        return response()->json([
            'lesson' => new LessonResource($lesson->load(self::WITH)),
            'absences_count' => count($data['absences']),
        ]);
    }

    /** Holders of the "full" permission reach any lesson; a teacher only their own (404 otherwise, nothing is revealed). */
    private function accessibleLesson(Request $request, string $id, string $fullPermission): Lesson
    {
        $lesson = Lesson::query()->findOrFail($id);

        if (! TeacherScope::canAccess($request->user(), $lesson, $fullPermission)) {
            abort(404);
        }

        return $lesson;
    }
}
