<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\SubjectCoefficientResource;
use App\Models\SubjectCoefficient;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Weight of a subject in the general average, per level (and optionally per track). */
final class SubjectCoefficientController extends ResourceController
{
    protected function model(): string
    {
        return SubjectCoefficient::class;
    }

    protected function resource(): string
    {
        return SubjectCoefficientResource::class;
    }

    protected function with(): array
    {
        return ['subject:id,code,name'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $immutable = $model === null ? [
            'level_id' => ['required', 'integer', TenantRules::exists('levels')],
            'track_id' => ['nullable', 'integer', TenantRules::exists('tracks')],
            'subject_id' => [
                'required', 'integer', TenantRules::exists('subjects'),
                TenantRules::unique('subject_coefficients', 'subject_id')
                    ->where('level_id', (int) $request->input('level_id'))
                    ->where('track_key', (int) $request->input('track_id', 0)),
            ],
        ] : [];

        return $immutable + ['coefficient' => ['required', 'integer', 'min:1', 'max:10']];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate(['level_id' => ['sometimes', 'integer'], 'track_id' => ['sometimes', 'integer']]);

        $query->when($request->query('level_id'), fn (Builder $q, $v) => $q->where('level_id', $v))
            ->when($request->query('track_id'), fn (Builder $q, $v) => $q->where('track_id', $v));
    }
}
