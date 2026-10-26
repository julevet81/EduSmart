<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Enrollments\CloseEnrollment;
use App\Actions\Enrollments\EnrollStudent;
use App\Actions\Enrollments\TransferEnrollment;
use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Student;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class EnrollmentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'group_id' => ['sometimes', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(EnrollmentStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $enrollments = Enrollment::query()
            ->with(['student:id,registration_number,first_name,last_name', 'group:id,name'])
            ->when($request->query('student_id'), fn ($q, string $v) => $q->where('student_id', $v))
            ->when($request->query('group_id'), fn ($q, string $v) => $q->where('group_id', $v))
            ->when($request->query('academic_year_id'), fn ($q, string $v) => $q->where('academic_year_id', $v))
            ->when($request->query('status'), fn ($q, string $v) => $q->where('status', $v))
            ->orderByDesc('id')
            ->cursorPaginate((int) $request->query('per_page', 25));

        return EnrollmentResource::collection($enrollments);
    }

    public function store(Request $request, EnrollStudent $enroll): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', TenantRules::exists('students')],
            'group_id' => ['required', 'integer', TenantRules::exists('groups')],
            'enrolled_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $enrollment = $enroll->handle(
            Student::query()->findOrFail($data['student_id']),
            Group::query()->findOrFail($data['group_id']),
            isset($data['enrolled_on']) ? Carbon::parse($data['enrolled_on']) : null,
            $data['notes'] ?? null,
        );

        return (new EnrollmentResource($enrollment))->response()->setStatusCode(201);
    }

    public function transfer(Request $request, TransferEnrollment $transfer, string $id): JsonResponse
    {
        $data = $request->validate(['group_id' => ['required', 'integer', TenantRules::exists('groups')]]);

        $new = $transfer->handle(
            Enrollment::query()->findOrFail($id),
            Group::query()->findOrFail($data['group_id']),
        );

        // The transfer creates a row, which would make Laravel answer 201; it is an action on an existing resource.
        return (new EnrollmentResource($new))->response()->setStatusCode(200);
    }

    public function close(Request $request, CloseEnrollment $close, string $id): EnrollmentResource
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(EnrollmentStatus::closing())],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return new EnrollmentResource($close->handle(
            Enrollment::query()->findOrFail($id),
            EnrollmentStatus::from($data['status']),
            $data['notes'] ?? null,
        ));
    }
}
