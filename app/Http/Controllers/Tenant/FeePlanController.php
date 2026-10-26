<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\BillingMode;
use App\Http\Resources\FeePlanResource;
use App\Models\FeePlan;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class FeePlanController extends ResourceController
{
    protected function model(): string
    {
        return FeePlan::class;
    }

    protected function resource(): string
    {
        return FeePlanResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        // Year and billing mode are fixed once created: student fees and invoices depend on them.
        $immutable = $model === null ? [
            'academic_year_id' => ['required', 'integer', TenantRules::exists('academic_years')],
            'billing_mode' => ['required', Rule::enum(BillingMode::class)],
        ] : [];

        return $immutable + [
            'subject_id' => ['nullable', 'integer', TenantRules::exists('subjects')],
            'name' => ['required', 'string', 'max:150'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'due_day' => ['integer', 'min:1', 'max:28'],
            'bill_absences' => ['boolean'],
            'is_active' => ['boolean'],
        ];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate(['academic_year_id' => ['sometimes', 'integer'], 'is_active' => ['sometimes', 'boolean']]);

        $query->when($request->query('academic_year_id'), fn (Builder $q, $v) => $q->where('academic_year_id', $v))
            ->when($request->has('is_active'), fn (Builder $q) => $q->where('is_active', $request->boolean('is_active')))
            ->when($request->query('q'), fn (Builder $q, $v) => $q->where('name', 'like', $this->like((string) $v)));
    }
}
