<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\Cycle;
use App\Http\Resources\LevelResource;
use App\Models\Level;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class LevelController extends ResourceController
{
    protected function model(): string
    {
        return Level::class;
    }

    protected function resource(): string
    {
        return LevelResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'code' => ['required', 'string', 'max:20', TenantRules::unique('levels', 'code', $model?->getKey())],
            'name' => ['required', 'string', 'max:255'],
            'name_fr' => ['nullable', 'string', 'max:255'],
            'cycle' => ['required', Rule::enum(Cycle::class)],
            'sort_order' => ['integer', 'min:0', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->query('cycle'), fn (Builder $q, string $c) => $q->where('cycle', $c))
            ->orderBy('sort_order');
    }
}
