<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\HolidayResource;
use App\Models\Holiday;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** School holidays and breaks: no lessons are generated on these days. */
final class HolidayController extends ResourceController
{
    protected function model(): string
    {
        return Holiday::class;
    }

    protected function resource(): string
    {
        return HolidayResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        $start = $data['starts_on'] ?? $model?->starts_on;
        $end = $data['ends_on'] ?? $model?->ends_on;

        if ($start > $end) {
            throw ValidationException::withMessages(['ends_on' => ['The end date must not be before the start date.']]);
        }

        $holiday = parent::persist($data, $model);

        // Untouched, not-yet-given lessons inside the holiday disappear. Customized or completed ones stay.
        Lesson::query()
            ->where('status', 'scheduled')->where('is_customized', false)
            ->whereBetween('date', [$holiday->starts_on, $holiday->ends_on])
            ->delete();

        return $holiday;
    }

    protected function filter(\Illuminate\Database\Eloquent\Builder $query, Request $request): void
    {
        $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d']]);

        $query->when($request->query('from'), fn ($q, string $v) => $q->where('ends_on', '>=', $v))
            ->when($request->query('to'), fn ($q, string $v) => $q->where('starts_on', '<=', $v))
            ->orderBy('starts_on');
    }
}
