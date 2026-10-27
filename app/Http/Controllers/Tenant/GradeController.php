<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\GradeStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Student;
use App\Support\Exams\ExamAccess;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The roster of an exam and the entry of its grades. */
final class GradeController extends Controller
{
    /** Students enrolled on the exam date (plus any student who already has a grade), with their grade if any. */
    public function show(Request $request, string $id): JsonResponse
    {
        return $this->roster(ExamAccess::find($request->user(), $id, 'exams.view'));
    }

    /**
     * Upserts the submitted grades; students left out are untouched. status: present (needs a score),
     * absent (leaves the average) or clear (removes the grade).
     */
    public function record(Request $request, string $id): JsonResponse
    {
        $exam = ExamAccess::find($request->user(), $id, 'grades.record');

        $data = $request->validate([
            'grades' => ['required', 'array', 'min:1', 'max:500'],
            'grades.*.student_id' => ['required', 'integer', 'distinct', TenantRules::exists('students')],
            'grades.*.status' => ['required', 'in:present,absent,clear'],
            'grades.*.score' => ['nullable', 'required_if:grades.*.status,present', 'numeric', 'decimal:0,2', 'min:0'],
            'grades.*.comment' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($request, $exam, $data): void {
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->getKey());

            if ($exam->is_locked) {
                abort(422, 'This exam is locked.');
            }

            $roster = $exam->enrolledStudentIds()->pluck('student_id')->all();

            foreach ($data['grades'] as $i => $row) {
                $studentId = (int) $row['student_id'];

                if (! in_array($studentId, $roster, true)) {
                    throw ValidationException::withMessages(["grades.{$i}.student_id" => 'The student was not enrolled in this group on the exam date.']);
                }

                $existing = Grade::query()->where('exam_id', $exam->getKey())->where('student_id', $studentId)->first();

                if ($row['status'] === 'clear') {
                    $existing?->delete();

                    continue;
                }

                $score = null;

                if ($row['status'] === GradeStatus::Present->value) {
                    $score = (int) round(((float) $row['score']) * 100);

                    if ($score > $exam->max_score * 100) {
                        throw ValidationException::withMessages(["grades.{$i}.score" => "The score cannot exceed {$exam->max_score}."]);
                    }
                }

                $values = [
                    'status' => $row['status'], 'score' => $score,
                    'comment' => $row['comment'] ?? null, 'entered_by' => $request->user()->getKey(),
                ];

                $existing === null
                    ? Grade::query()->create($values + ['exam_id' => $exam->getKey(), 'student_id' => $studentId])
                    : $existing->update($values);
            }
        });

        return $this->roster($exam);
    }

    private function roster(Exam $exam): JsonResponse
    {
        $grades = Grade::query()->where('exam_id', $exam->getKey())->get()->keyBy('student_id');

        $students = Student::query()->withTrashed()
            ->select(['id', 'registration_number', 'first_name', 'last_name'])
            ->where(fn ($q) => $q->whereIn('id', $exam->enrolledStudentIds())->orWhereIn('id', $grades->keys()))
            ->orderBy('last_name')->orderBy('first_name')->get();

        return response()->json([
            'exam' => new ExamResource($exam->load(['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name'])),
            'data' => $students->map(function (Student $s) use ($grades): array {
                $grade = $grades->get($s->getKey());

                return [
                    'student' => $s->only(['id', 'registration_number', 'first_name', 'last_name']),
                    'status' => $grade?->status->value,
                    'score' => $grade?->score === null ? null : round($grade->score / 100, 2),
                    'comment' => $grade?->comment,
                ];
            })->values(),
        ]);
    }
}
