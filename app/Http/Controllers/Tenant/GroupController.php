<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\GroupResource;
use App\Models\Group;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class GroupController extends ResourceController
{
    protected function model(): string
    {
        return Group::class;
    }

    protected function resource(): string
    {
        return GroupResource::class;
    }

    protected function with(): array
    {
        return ['level', 'track', 'room'];
    }

    protected function counts(): array
    {
        return ['activeEnrollments'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        // Year and level are fixed once created: enrollments, grades and fees depend on them.
        $immutable = $model === null ? [
            'academic_year_id' => ['required', 'integer', TenantRules::exists('academic_years')],
            'level_id' => ['required', 'integer', TenantRules::exists('levels')],
        ] : [];

        $yearId = $model?->academic_year_id ?? $request->input('academic_year_id');

        return $immutable + [
            'track_id' => ['nullable', 'integer', TenantRules::exists('tracks')],
            'room_id' => ['nullable', 'integer', TenantRules::exists('rooms')],
            'name' => [
                'required', 'string', 'max:100',
                TenantRules::unique('groups', 'name', $model?->getKey())->where('academic_year_id', $yearId),
            ],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate([
            'academic_year_id' => ['sometimes', 'integer'],
            'level_id' => ['sometimes', 'integer'],
        ]);

        $query->when($request->query('academic_year_id'), fn (Builder $q, string $v) => $q->where('academic_year_id', $v))
            ->when($request->query('level_id'), fn (Builder $q, string $v) => $q->where('level_id', $v))
            ->when($request->query('q'), fn (Builder $q, string $v) => $q->where('name', 'like', $this->like($v)));
    }
}
