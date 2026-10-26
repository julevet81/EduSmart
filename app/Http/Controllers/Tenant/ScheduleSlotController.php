<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\ScheduleSlotResource;
use App\Models\AcademicYear;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\ScheduleSlot;
use App\Support\Schedule\ConflictChecker;
use App\Support\Schedule\ScheduleLock;
use App\Support\Schedule\Time;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Weekly recurring timetable rules, with teacher / group / room clash prevention. */
final class ScheduleSlotController extends ResourceController
{
    private const ATTRIBUTES = [
        'group_id', 'subject_id', 'teacher_id', 'room_id', 'weekday',
        'starts_at', 'ends_at', 'valid_from', 'valid_until', 'is_active',
    ];

    public function __construct(private readonly ConflictChecker $conflicts) {}

    protected function model(): string
    {
        return ScheduleSlot::class;
    }

    protected function resource(): string
    {
        return ScheduleSlotResource::class;
    }

    protected function with(): array
    {
        return ['group:id,name', 'subject:id,code,name', 'teacher:id,first_name,last_name', 'room:id,name'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        // The group (hence the academic year) is fixed once the slot exists.
        $create = $model === null ? ['group_id' => ['required', 'integer', TenantRules::exists('groups')]] : [];

        // Ordering rules (end after start, ...) are checked in persist() on the merged values,
        // because a partial update may carry only one of the two fields.
        return $create + [
            'subject_id' => ['required', 'integer', TenantRules::exists('subjects')],
            'teacher_id' => ['required', 'integer', TenantRules::exists('teachers')->whereNull('deleted_at')],
            'room_id' => ['nullable', 'integer', TenantRules::exists('rooms')],
            'weekday' => ['required', 'integer', 'between:0,6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'is_active' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        ScheduleLock::acquire();

        $attrs = $model === null ? $data : [...$model->only(self::ATTRIBUTES), ...$data];

        $group = Group::query()->findOrFail($attrs['group_id']);
        $year = AcademicYear::query()->findOrFail($group->academic_year_id);

        $attrs['academic_year_id'] = $year->getKey();
        $attrs['starts_at'] = Time::normalize($attrs['starts_at']);
        $attrs['ends_at'] = Time::normalize($attrs['ends_at']);
        $attrs['valid_from'] = $attrs['valid_from'] ?? $year->starts_on->toDateString();
        $attrs['valid_until'] = $attrs['valid_until'] ?? $year->ends_on->toDateString();

        $errors = [];

        if (! $group->is_active) {
            $errors['group_id'] = ['This group is not active.'];
        }
        if ($attrs['starts_at'] >= $attrs['ends_at']) {
            $errors['ends_at'] = ['The end time must be after the start time.'];
        }
        if ($attrs['valid_from'] > $attrs['valid_until']) {
            $errors['valid_until'] = ['The end of validity must not be before its start.'];
        }
        if ($attrs['valid_from'] < $year->starts_on->toDateString() || $attrs['valid_until'] > $year->ends_on->toDateString()) {
            $errors['valid_from'] = ['The validity period must stay inside the academic year.'];
        }
        if ($errors === [] && ($attrs['is_active'] ?? true)) {
            $errors = $this->conflicts->forSlot($attrs, $model?->getKey());
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $slot = parent::persist($attrs, $model);

        if ($model !== null) {
            // Already-generated future lessons no longer match the rule: drop the untouched ones,
            // the next generation recreates them from the new rule.
            Lesson::purgeGeneratedFor((int) $slot->getKey());
        }

        return $slot;
    }

    public function destroy(string $id): Response
    {
        $slot = $this->find($id);

        DB::transaction(fn () => Lesson::purgeGeneratedFor((int) $slot->getKey()));

        return parent::destroy($id);
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate([
            'group_id' => ['sometimes', 'integer'],
            'teacher_id' => ['sometimes', 'integer'],
            'room_id' => ['sometimes', 'integer'],
            'weekday' => ['sometimes', 'integer', 'between:0,6'],
        ]);

        foreach (['group_id', 'teacher_id', 'room_id', 'weekday'] as $field) {
            $query->when($request->query($field) !== null, fn (Builder $q) => $q->where($field, $request->query($field)));
        }

        $query->orderBy('weekday')->orderBy('starts_at');
    }
}
