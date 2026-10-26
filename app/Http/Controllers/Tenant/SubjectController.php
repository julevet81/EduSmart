<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\SubjectResource;
use App\Models\Subject;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class SubjectController extends ResourceController
{
    protected function model(): string
    {
        return Subject::class;
    }

    protected function resource(): string
    {
        return SubjectResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'code' => ['required', 'string', 'max:20', TenantRules::unique('subjects', 'code', $model?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->query('q'), fn (Builder $b, string $q) => $b->where(
            fn (Builder $w) => $w->where('name', 'like', $this->like($q))->orWhere('code', 'like', $this->like($q))
        ));
    }
}
