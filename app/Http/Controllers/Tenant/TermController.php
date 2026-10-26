<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Resources\TermResource;
use App\Models\AcademicYear;
use App\Models\Term;
use App\Support\Validation\TenantRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TermController extends Controller
{
    public function store(Request $request, string $yearId): JsonResponse
    {
        $year = AcademicYear::query()->findOrFail($yearId);

        $data = $request->validate($this->rules($year, null));
        $term = Term::query()->create($data + ['academic_year_id' => $year->getKey()]);

        return (new TermResource($term))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $id): TermResource
    {
        $term = Term::query()->with('academicYear')->findOrFail($id);

        $rules = array_map(static fn (array $r): array => ['sometimes', ...$r], $this->rules($term->academicYear, $term));
        $term->update($request->validate($rules));

        return new TermResource($term);
    }

    /** @return array<string, list<mixed>> */
    private function rules(AcademicYear $year, ?Term $term): array
    {
        $within = [
            'after_or_equal:'.$year->starts_on->toDateString(),
            'before_or_equal:'.$year->ends_on->toDateString(),
        ];

        return [
            'number' => [
                'required', 'integer', 'between:1,3',
                Rule::unique('terms', 'number')->where('academic_year_id', $year->getKey())->ignore($term?->getKey()),
            ],
            'name' => ['required', 'string', 'max:50'],
            'starts_on' => ['required', 'date', ...$within],
            'ends_on' => ['required', 'date', 'after:starts_on', ...$within],
        ];
    }
}
