<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\AbsenceType;
use App\Enums\EnrollmentStatus;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\LessonResource;
use App\Http\Resources\PortalAbsenceResource;
use App\Http\Resources\PortalPaymentResource;
use App\Models\Absence;
use App\Models\Enrollment;
use App\Models\Invoice;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\Student;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Read-only views for guardians and students. Ownership of {student} is enforced by EnsurePortalAccess;
 * attendance summary, report card and account reuse the staff controllers behind the same gate.
 */
final class PortalController extends Controller
{
    public function students(Request $request): JsonResponse
    {
        /** @var array<int, bool> $access */
        $access = $request->attributes->get('portal_students');
        $groups = $this->currentGroups(array_keys($access));

        return response()->json(['data' => Student::query()->whereIn('id', array_keys($access))->orderBy('id')->get()
            ->map(fn (Student $s): array => $this->profile($s, $groups[$s->getKey()] ?? null, $access[$s->getKey()]))->all()]);
    }

    public function student(Request $request, string $student): JsonResponse
    {
        /** @var array<int, bool> $access */
        $access = $request->attributes->get('portal_students');
        $model = Student::query()->findOrFail($student);

        return response()->json(['data' => $this->profile($model, $this->currentGroups([$model->getKey()])[$model->getKey()] ?? null, $access[$model->getKey()])]);
    }

    public function absences(Request $request, string $student): AnonymousResourceCollection
    {
        $request->validate([
            'type' => ['sometimes', Rule::enum(AbsenceType::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $from = $request->query('from');
        $to = $request->query('to');

        $query = Absence::query()->with('lesson:id,date,starts_at,ends_at,subject_id', 'lesson.subject:id,name')
            ->where('student_id', (int) $student)
            ->when($request->query('type'), fn (Builder $q, string $v) => $q->where('type', $v))
            ->when($from !== null || $to !== null, fn (Builder $q) => $q->whereHas('lesson', fn (Builder $l) => $l
                ->when($from, fn ($x, string $v) => $x->where('date', '>=', $v))
                ->when($to, fn ($x, string $v) => $x->where('date', '<=', $v))));

        return PortalAbsenceResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    /** The timetable of a student between two dates (at most 31 days), limited to the groups they were enrolled in on each date. */
    public function lessons(Request $request, TenantContext $tenant, string $student): AnonymousResourceCollection
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        if (Carbon::parse($data['from'])->diffInDays(Carbon::parse($data['to'])) > 31) {
            abort(422, 'The period must not exceed 31 days.');
        }

        $tenantId = (int) $tenant->require()->getKey();

        $lessons = Lesson::query()->with(['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name', 'room:id,name'])
            ->whereBetween('date', [$data['from'], $data['to']])
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('enrollments')
                ->whereColumn('enrollments.group_id', 'lessons.group_id')
                ->where('enrollments.student_id', (int) $student)->where('enrollments.tenant_id', $tenantId)
                ->whereRaw('lessons.date >= DATE(enrollments.enrolled_on)')
                ->whereRaw('(enrollments.ended_on IS NULL OR lessons.date <= DATE(enrollments.ended_on))'))
            ->orderBy('date')->orderBy('starts_at')->get();

        return LessonResource::collection($lessons);
    }

    public function invoices(Request $request, string $student): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', Rule::enum(InvoiceStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        return InvoiceResource::collection(Invoice::query()->where('student_id', (int) $student)
            ->when($request->query('status'), fn (Builder $q, string $v) => $q->where('status', $v))
            ->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function payments(Request $request, string $student): AnonymousResourceCollection
    {
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return PortalPaymentResource::collection(Payment::query()->where('student_id', (int) $student)
            ->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    /** @param  list<int>  $studentIds @return array<int, array<string, mixed>> student id => current group */
    private function currentGroups(array $studentIds): array
    {
        return Enrollment::query()->with('group:id,name,level_id', 'group.level:id,code,name')
            ->whereIn('student_id', $studentIds)->where('status', EnrollmentStatus::Active->value)->get()
            ->mapWithKeys(fn (Enrollment $e): array => [$e->student_id => [
                'id' => $e->group?->getKey(), 'name' => $e->group?->name, 'level' => $e->group?->level?->name,
            ]])->all();
    }

    /** @param  array<string, mixed>|null  $group @return array<string, mixed> */
    private function profile(Student $s, ?array $group, bool $finance): array
    {
        return [
            'id' => $s->getKey(),
            'registration_number' => $s->registration_number,
            'first_name' => $s->first_name,
            'last_name' => $s->last_name,
            'birth_date' => $s->birth_date?->toDateString(),
            'current_group' => $group,
            'can_view_finance' => $finance,
        ];
    }
}
