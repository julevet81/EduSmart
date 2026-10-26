<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Actions\Schedule\GenerateLessons;
use App\Enums\LessonKind;
use App\Enums\LessonStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LessonResource;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Lesson;
use App\Support\Schedule\ConflictChecker;
use App\Support\Schedule\ScheduleLock;
use App\Support\Schedule\TeacherScope;
use App\Support\Schedule\Time;
use App\Support\Validation\TenantRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class LessonController extends Controller
{
    private const WITH = ['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name', 'room:id,name'];

    public function __construct(private readonly ConflictChecker $conflicts) {}

    /** Timetable view. Filters: from/to (default: next 14 days), group_id, teacher_id, room_id, status. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'group_id' => ['sometimes', 'integer'],
            'teacher_id' => ['sometimes', 'integer'],
            'room_id' => ['sometimes', 'integer'],
            'status' => ['sometimes', Rule::enum(LessonStatus::class)],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ]);

        $from = CarbonImmutable::parse($request->query('from', today()->toDateString()));
        $to = CarbonImmutable::parse($request->query('to', $from->addDays(13)->toDateString()));

        if ($to->lt($from) || $from->diffInDays($to) > 92) {
            abort(422, 'The date range must be between 0 and 92 days.');
        }

        $query = Lesson::query()->with(self::WITH)->whereBetween('date', [$from->toDateString(), $to->toDateString()]);

        foreach (['group_id', 'teacher_id', 'room_id', 'status'] as $field) {
            $query->when($request->query($field) !== null, fn ($q) => $q->where($field, $request->query($field)));
        }

        TeacherScope::restrict($query, $request->user(), 'schedule.view');

        return LessonResource::collection(
            $query->orderBy('date')->orderBy('starts_at')->orderBy('id')->cursorPaginate((int) $request->query('per_page', 100))
        );
    }

    public function show(Request $request, string $id): LessonResource
    {
        $query = Lesson::query()->with(self::WITH);
        TeacherScope::restrict($query, $request->user(), 'schedule.view');

        return new LessonResource($query->findOrFail($id));
    }

    /** Adds a one-off (make-up) lesson. Regular lessons come from slots through generate(). */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'group_id' => ['required', 'integer', TenantRules::exists('groups')],
            'subject_id' => ['required', 'integer', TenantRules::exists('subjects')],
            'teacher_id' => ['required', 'integer', TenantRules::exists('teachers')->whereNull('deleted_at')],
            'room_id' => ['nullable', 'integer', TenantRules::exists('rooms')],
            'date' => ['required', 'date_format:Y-m-d'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $lesson = DB::transaction(function () use ($data): Lesson {
            ScheduleLock::acquire();

            $group = Group::query()->findOrFail($data['group_id']);
            $attrs = $data + [
                'academic_year_id' => $group->academic_year_id,
                'kind' => LessonKind::Makeup,
                'status' => LessonStatus::Scheduled,
                'is_customized' => true,
            ];

            $this->assertValid($attrs, null);

            return Lesson::query()->create($attrs);
        });

        return (new LessonResource($lesson->load(self::WITH)))->response()->setStatusCode(201);
    }

    /** Moves a scheduled lesson (another room, teacher, day or time). Completed/cancelled lessons keep only their note. */
    public function update(Request $request, string $id): LessonResource
    {
        $data = $request->validate([
            'teacher_id' => ['sometimes', 'integer', TenantRules::exists('teachers')->whereNull('deleted_at')],
            'room_id' => ['sometimes', 'nullable', 'integer', TenantRules::exists('rooms')],
            'date' => ['sometimes', 'date_format:Y-m-d'],
            'starts_at' => ['sometimes', 'date_format:H:i'],
            'ends_at' => ['sometimes', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $lesson = DB::transaction(function () use ($id, $data): Lesson {
            ScheduleLock::acquire();

            $lesson = Lesson::query()->lockForUpdate()->findOrFail($id);
            $moves = array_diff_key($data, ['note' => true]);

            if ($moves !== []) {
                if ($lesson->status !== LessonStatus::Scheduled) {
                    abort(422, 'Only the note of a completed or cancelled lesson can be changed.');
                }

                $attrs = [...$lesson->only(['group_id', 'teacher_id', 'room_id', 'date', 'starts_at', 'ends_at']), ...$data];
                $this->assertValid($attrs, (int) $lesson->getKey());
                $data['is_customized'] = true;
            }

            $lesson->update($data);

            return $lesson;
        });

        return new LessonResource($lesson->load(self::WITH));
    }

    public function cancel(Request $request, string $id): LessonResource
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        $lesson = DB::transaction(function () use ($id, $data): Lesson {
            $lesson = Lesson::query()->lockForUpdate()->findOrFail($id);

            if ($lesson->status !== LessonStatus::Scheduled) {
                abort(422, 'Only a scheduled lesson can be cancelled.');
            }

            $lesson->update(['status' => LessonStatus::Cancelled, 'note' => $data['note'] ?? $lesson->note]);

            return $lesson;
        });

        return new LessonResource($lesson->load(self::WITH));
    }

    public function generate(Request $request, GenerateLessons $generator): JsonResponse
    {
        $data = $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $from = CarbonImmutable::parse($data['from']);
        $to = CarbonImmutable::parse($data['to']);

        if ($from->diffInDays($to) > 62) {
            abort(422, 'Generate at most 62 days at a time.');
        }

        return response()->json($generator->handle($from, $to));
    }

    /**
     * @param  array<string, mixed>  $attrs  group_id, teacher_id, room_id, date, starts_at, ends_at
     */
    private function assertValid(array &$attrs, ?int $ignoreLessonId): void
    {
        $attrs['starts_at'] = Time::normalize($attrs['starts_at']);
        $attrs['ends_at'] = Time::normalize($attrs['ends_at']);

        $errors = [];

        if ($attrs['starts_at'] >= $attrs['ends_at']) {
            $errors['ends_at'] = ['The end time must be after the start time.'];
        }

        $group = Group::query()->findOrFail($attrs['group_id']);
        $year = AcademicYear::query()->findOrFail($group->academic_year_id);

        if ($attrs['date'] < $year->starts_on->toDateString() || $attrs['date'] > $year->ends_on->toDateString()) {
            $errors['date'] = ['The date must be inside the academic year of the group.'];
        }

        $errors = $errors === [] ? $this->conflicts->forLesson($attrs, $ignoreLessonId) : $errors;

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
