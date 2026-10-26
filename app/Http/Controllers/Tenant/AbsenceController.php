<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\AbsenceType;
use App\Http\Controllers\Controller;
use App\Http\Resources\AbsenceResource;
use App\Models\Absence;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class AbsenceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'student_id' => ['sometimes', 'integer'],
            'group_id' => ['sometimes', 'integer'],
            'type' => ['sometimes', Rule::enum(AbsenceType::class)],
            'is_justified' => ['sometimes', 'boolean'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Absence::query()
            ->with(['student:id,registration_number,first_name,last_name', 'lesson:id,date,starts_at,ends_at,group_id,subject_id'])
            ->when($request->query('student_id'), fn ($q, string $v) => $q->where('student_id', $v))
            ->when($request->query('type'), fn ($q, string $v) => $q->where('type', $v))
            ->when($request->has('is_justified'), fn ($q) => $q->where('is_justified', $request->boolean('is_justified')));

        $from = $request->query('from');
        $to = $request->query('to');
        $groupId = $request->query('group_id');

        if ($from !== null || $to !== null || $groupId !== null) {
            $query->whereHas('lesson', function (Builder $l) use ($from, $to, $groupId): void {
                $l->when($from, fn ($q, string $v) => $q->where('date', '>=', $v))
                    ->when($to, fn ($q, string $v) => $q->where('date', '<=', $v))
                    ->when($groupId, fn ($q, string $v) => $q->where('group_id', $v));
            });
        }

        return AbsenceResource::collection($query->orderByDesc('id')->cursorPaginate((int) $request->query('per_page', 25)));
    }

    public function justify(Request $request, string $id): AbsenceResource
    {
        $data = $request->validate([
            'is_justified' => ['required', 'boolean'],
            'justification' => ['nullable', 'string', 'max:500'],
        ]);

        $absence = Absence::query()->findOrFail($id);
        $absence->update([
            'is_justified' => $data['is_justified'],
            'justification' => $data['is_justified'] ? ($data['justification'] ?? null) : null,
            'justified_at' => $data['is_justified'] ? now() : null,
        ]);

        return new AbsenceResource($absence);
    }
}
