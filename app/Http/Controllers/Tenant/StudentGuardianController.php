<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\Relationship;
use App\Http\Controllers\Controller;
use App\Http\Resources\StudentResource;
use App\Models\Student;
use App\Support\Audit\AuditRecorder;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\TenantRules;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Links guardians to students (many-to-many, with relationship and billing responsibility). */
final class StudentGuardianController extends Controller
{
    public function store(Request $request, TenantContext $tenant, string $studentId): StudentResource
    {
        $student = Student::query()->findOrFail($studentId);

        $data = $request->validate([
            'guardian_id' => ['required', 'integer', TenantRules::exists('guardians')],
            'relationship' => ['required', Rule::enum(Relationship::class)],
            'is_primary' => ['boolean'],
            'is_financial_responsible' => ['boolean'],
        ]);

        DB::transaction(function () use ($student, $data, $tenant): void {
            if ($data['is_primary'] ?? false) {
                // Only one primary contact per student.
                DB::table('guardian_student')->where('student_id', $student->getKey())->update(['is_primary' => false]);
            }

            $student->guardians()->syncWithoutDetaching([$data['guardian_id'] => [
                'tenant_id' => $tenant->id(),
                'relationship' => $data['relationship'],
                'is_primary' => $data['is_primary'] ?? false,
                'is_financial_responsible' => $data['is_financial_responsible'] ?? false,
            ]]);

            AuditRecorder::event('student.guardian_linked', ['student_id' => $student->getKey(), 'guardian_id' => $data['guardian_id']]);
        });

        return new StudentResource($student->load(['guardians', 'currentEnrollment']));
    }

    public function destroy(string $studentId, string $guardianId): Response
    {
        $student = Student::query()->findOrFail($studentId);

        $student->guardians()->detach($guardianId);
        AuditRecorder::event('student.guardian_unlinked', ['student_id' => $student->getKey(), 'guardian_id' => (int) $guardianId]);

        return response()->noContent();
    }
}
