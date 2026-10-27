<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\ExamKind;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExamResource;
use App\Models\Exam;
use App\Models\Group;
use App\Models\Term;
use App\Support\Exams\ExamAccess;
use App\Support\Schedule\TeacherScope;
use App\Support\Validation\TenantRules;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class ExamController extends Controller
{
    private const WITH = ['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'group_id' => ['sometimes', 'integer'],
            'subject_id' => ['sometimes', 'integer'],
            'term_id' => ['sometimes', 'integer'],
            'academic_year_id' => ['sometimes', 'integer'],
            'teacher_id' => ['sometimes', 'integer'],
            'kind' => ['sometimes', Rule::enum(ExamKind::class)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'q' => ['sometimes', 'string', 'max:100'],
        ]);

        $query = Exam::query()->with(self::WITH)->withCount('grades');
        TeacherScope::restrict($query, $request->user(), 'exams.view');

        foreach (['group_id', 'subject_id', 'term_id', 'academic_year_id', 'teacher_id', 'kind'] as $filter) {
            $query->when($request->query($filter), fn ($q, $v) => $q->where($filter, $v));
        }

        $query->when($request->query('from'), fn ($q, $v) => $q->where('date', '>=', $v))
            ->when($request->query('to'), fn ($q, $v) => $q->where('date', '<=', $v))
            ->when($request->query('q'), fn ($q, $v) => $q->where('name', 'like', '%'.addcslashes((string) $v, '%_\\').'%'));

        return ExamResource::collection($query->orderByDesc('date')->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function show(Request $request, string $id): ExamResource
    {
        $exam = ExamAccess::find($request->user(), $id, 'exams.view');

        return new ExamResource($exam->load(self::WITH)->loadCount('grades'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', TenantRules::exists('groups')],
            'subject_id' => ['required', 'integer', TenantRules::exists('subjects')],
            'term_id' => ['required', 'integer', TenantRules::exists('terms')],
            'teacher_id' => ['nullable', 'integer', TenantRules::exists('teachers')],
            ...$this->fields(),
        ]);

        $group = Group::query()->findOrFail($data['group_id']);
        $term = Term::query()->findOrFail($data['term_id']);

        if ($term->academic_year_id !== $group->academic_year_id) {
            abort(422, 'The term does not belong to the academic year of the group.');
        }

        $this->assertDateInTerm($data['date'], $term);

        if ($request->user()->can('exams.manage')) {
            $teacherId = $data['teacher_id'] ?? null;
        } else {
            // A teacher can only set exams for themselves, in a group and subject they teach.
            $teacherId = TeacherScope::teacherIdOf($request->user());

            if ($teacherId === null || ! ExamAccess::teaches($teacherId, (int) $group->getKey(), (int) $data['subject_id'])) {
                abort(403, 'You do not teach this subject in this group.');
            }
        }

        $exam = Exam::query()->create([
            'academic_year_id' => $group->academic_year_id,
            'group_id' => $group->getKey(),
            'subject_id' => $data['subject_id'],
            'term_id' => $term->getKey(),
            'teacher_id' => $teacherId,
            'name' => $data['name'],
            'kind' => $data['kind'] ?? ExamKind::Exam->value,
            'date' => $data['date'],
            'max_score' => $data['max_score'] ?? 20,
            'coefficient' => $data['coefficient'] ?? 1,
            'created_by' => $request->user()->getKey(),
        ]);

        return (new ExamResource($exam->load(self::WITH)->loadCount('grades')))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): ExamResource
    {
        $exam = ExamAccess::find($request->user(), $id, 'exams.manage');
        $rules = array_map(static fn (array $r): array => ['sometimes', ...$r], $this->fields());
        $rules['teacher_id'] = ['sometimes', 'nullable', 'integer', TenantRules::exists('teachers')];
        $data = $request->validate($rules);

        return DB::transaction(function () use ($request, $exam, $data): ExamResource {
            $exam = Exam::query()->lockForUpdate()->findOrFail($exam->getKey());

            if ($exam->is_locked) {
                abort(422, 'This exam is locked.');
            }

            if (array_key_exists('max_score', $data) && $data['max_score'] !== $exam->max_score && $exam->grades()->exists()) {
                abort(422, 'The maximum score cannot change once grades are entered.');
            }

            if (isset($data['date'])) {
                $this->assertDateInTerm($data['date'], Term::query()->findOrFail($exam->term_id));
            }

            if (! $request->user()->can('exams.manage')) {
                unset($data['teacher_id']);
            }

            $exam->update($data);

            return new ExamResource($exam->load(self::WITH)->loadCount('grades'));
        });
    }

    public function destroy(Request $request, string $id): Response
    {
        $exam = ExamAccess::find($request->user(), $id, 'exams.manage');

        if ($exam->is_locked) {
            abort(422, 'This exam is locked.');
        }

        try {
            $exam->delete();
        } catch (QueryException $e) {
            if (! str_starts_with((string) $e->getCode(), '23')) {
                throw $e;
            }

            abort(409, 'This exam has grades and cannot be deleted.');
        }

        return response()->noContent();
    }

    public function lock(Request $request, string $id): ExamResource
    {
        $exam = Exam::query()->findOrFail($id);
        $exam->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $request->user()->getKey()]);

        return new ExamResource($exam->load(self::WITH)->loadCount('grades'));
    }

    public function unlock(string $id): ExamResource
    {
        $exam = Exam::query()->findOrFail($id);
        $exam->update(['is_locked' => false, 'locked_at' => null, 'locked_by' => null]);

        return new ExamResource($exam->load(self::WITH)->loadCount('grades'));
    }

    /** @return array<string, list<mixed>> */
    private function fields(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['sometimes', Rule::enum(ExamKind::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'max_score' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'coefficient' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ];
    }

    private function assertDateInTerm(string $date, Term $term): void
    {
        if ($date < $term->starts_on->toDateString() || $date > $term->ends_on->toDateString()) {
            abort(422, 'The exam date is outside the term.');
        }
    }
}
