<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\EnrollmentStatus;
use App\Enums\GradeStatus;
use App\Http\Controllers\Controller;
use App\Models\Enrollment;
use App\Models\Exam;
use App\Models\Grade;
use App\Models\Group;
use App\Models\Student;
use App\Models\Term;
use App\Models\TermRemark;
use App\Support\Exams\ResultsCalculator as Calc;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Group rankings and individual report cards. term_id omitted = the whole academic year. */
final class ResultsController extends Controller
{
    public function __construct(private readonly Calc $calculator) {}

    public function group(Request $request, string $group): JsonResponse
    {
        $data = $request->validate(['term_id' => ['sometimes', 'integer', TenantRules::exists('terms')]]);
        $group = Group::query()->findOrFail($group);
        $termId = isset($data['term_id']) ? (int) $data['term_id'] : null;
        $result = $this->calculator->forGroup($group, $termId);

        return response()->json([
            'group' => ['id' => $group->getKey(), 'name' => $group->name],
            'term_id' => $termId,
            'subjects' => array_map(fn (array $s): array => $this->subjectStats($s), $result['subjects']),
            'students' => array_map(fn (array $row): array => [
                'student' => $row['student'],
                'subjects' => (object) array_map(fn (?int $v): ?float => Calc::points($v), $row['subjects']),
                'average' => Calc::points($row['average']),
                'rank' => $row['rank'],
                'appreciation' => Calc::appreciation($row['average']),
            ], $result['students']),
            'stats' => $this->stats($result['stats']),
        ]);
    }

    public function reportCard(Request $request, string $student): JsonResponse
    {
        $data = $request->validate([
            'term_id' => ['sometimes', 'integer', TenantRules::exists('terms')],
            'group_id' => ['sometimes', 'integer', TenantRules::exists('groups')],
        ]);
        $student = Student::query()->findOrFail($student);

        $enrollment = Enrollment::query()->where('student_id', $student->getKey())
            ->when(isset($data['group_id']), fn ($q) => $q->where('group_id', (int) $data['group_id']))
            ->when(! isset($data['group_id']), fn ($q) => $q->where('status', EnrollmentStatus::Active->value))
            ->orderByDesc('id')->first();

        if ($enrollment === null) {
            abort(422, 'The student has no enrollment to build a report card from.');
        }

        $group = Group::query()->with(['level:id,code,name', 'track:id,code,name', 'academicYear:id,name'])->findOrFail($enrollment->group_id);
        $termId = isset($data['term_id']) ? (int) $data['term_id'] : null;
        $result = $this->calculator->forGroup($group, $termId);

        $row = collect($result['students'])->first(fn (array $r): bool => $r['student']['id'] === $student->getKey());

        if ($row === null) {
            abort(404);
        }

        $exams = Exam::query()->where('group_id', $group->getKey())->when($termId !== null, fn ($q) => $q->where('term_id', $termId))
            ->orderBy('date')->get();
        $grades = Grade::query()->where('student_id', $student->getKey())->whereIn('exam_id', $exams->modelKeys())->get()->keyBy('exam_id');
        $term = $termId === null ? null : Term::query()->find($termId);

        return response()->json([
            'student' => $row['student'],
            'group' => ['id' => $group->getKey(), 'name' => $group->name, 'level' => $group->level?->name, 'track' => $group->track?->name],
            'academic_year' => $group->academicYear?->name,
            'term' => $term === null ? null : ['id' => $term->getKey(), 'name' => $term->name],
            'subjects' => array_map(fn (array $s): array => $this->subjectStats($s) + [
                'student_average' => Calc::points($row['subjects'][$s['id']] ?? null),
                'exams' => $exams->where('subject_id', $s['id'])->map(function (Exam $e) use ($grades): array {
                    $g = $grades->get($e->getKey());

                    return [
                        'exam_id' => $e->getKey(), 'name' => $e->name, 'kind' => $e->kind->value, 'date' => (string) $e->date,
                        'max_score' => $e->max_score, 'coefficient' => $e->coefficient,
                        'status' => $g?->status->value,
                        'score' => $g?->status === GradeStatus::Present ? Calc::points($g->score) : null,
                    ];
                })->values(),
            ], $result['subjects']),
            'average' => Calc::points($row['average']),
            'rank' => $row['rank'],
            'class_size' => $result['stats']['graded'],
            'appreciation' => Calc::appreciation($row['average']),
            'class' => $this->stats($result['stats']),
            'remark' => $termId === null ? null : TermRemark::query()->where('student_id', $student->getKey())->where('term_id', $termId)->value('remark'),
        ]);
    }

    /** @param  array<string, mixed>  $s @return array<string, mixed> */
    private function subjectStats(array $s): array
    {
        return [
            'id' => $s['id'], 'code' => $s['code'], 'name' => $s['name'], 'coefficient' => $s['coefficient'],
            'class_average' => Calc::points($s['average']), 'class_min' => Calc::points($s['min']), 'class_max' => Calc::points($s['max']),
        ];
    }

    /** @param  array<string, mixed>  $s @return array<string, mixed> */
    private function stats(array $s): array
    {
        return [
            'size' => $s['size'], 'graded' => $s['graded'],
            'average' => Calc::points($s['average']), 'min' => Calc::points($s['min']), 'max' => Calc::points($s['max']),
        ];
    }
}