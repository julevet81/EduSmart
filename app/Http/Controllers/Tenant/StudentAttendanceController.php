<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Absence;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Attendance figures of one student over a period (completed lessons only; lateness counts as present). */
final class StudentAttendanceController extends Controller
{
    public function summary(Request $request, TenantContext $tenant, string $studentId): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 366) {
            abort(422, 'The period must not exceed one year.');
        }

        $student = Student::query()->findOrFail($studentId);
        $tenantId = (int) $tenant->require()->getKey();

        // Completed lessons of the groups the student belonged to, counted only for the dates of each enrollment.
        $lessonsCount = (int) DB::table('lessons')
            ->join('enrollments', function ($join) use ($student, $tenantId): void {
                $join->on('enrollments.group_id', '=', 'lessons.group_id')
                    ->where('enrollments.student_id', '=', $student->getKey())
                    ->where('enrollments.tenant_id', '=', $tenantId);
            })
            ->where('lessons.tenant_id', $tenantId)
            ->where('lessons.status', 'completed')
            ->whereBetween('lessons.date', [$data['from'], $data['to']])
            ->whereRaw('lessons.date >= DATE(enrollments.enrolled_on)')
            ->whereRaw('(enrollments.ended_on IS NULL OR lessons.date <= DATE(enrollments.ended_on))')
            ->distinct()
            ->count('lessons.id');

        $rows = Absence::query()
            ->where('student_id', $student->getKey())
            ->whereHas('lesson', fn (Builder $l) => $l->whereBetween('date', [$data['from'], $data['to']]))
            ->selectRaw('type, is_justified, COUNT(*) AS total')
            ->groupBy('type', 'is_justified')
            ->toBase()   // raw rows: no enum casts, so "type" stays a plain string
            ->get();

        $absent = (int) $rows->where('type', 'absent')->sum('total');
        $late = (int) $rows->where('type', 'late')->sum('total');
        $justified = (int) $rows->where('type', 'absent')->where('is_justified', true)->sum('total');

        return response()->json([
            'student_id' => $student->getKey(),
            'from' => $data['from'],
            'to' => $data['to'],
            'lessons_count' => $lessonsCount,
            'absent' => $absent,
            'late' => $late,
            'justified_absences' => $justified,
            'unjustified_absences' => $absent - $justified,
            'attendance_rate' => $lessonsCount > 0 ? round(($lessonsCount - $absent) / $lessonsCount * 100, 1) : null,
        ]);
    }
}
