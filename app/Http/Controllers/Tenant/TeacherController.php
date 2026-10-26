<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\TeacherResource;
use App\Models\Teacher;
use App\Support\Tenancy\TenantContext;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TeacherController extends ResourceController
{
    protected function model(): string
    {
        return Teacher::class;
    }

    protected function resource(): string
    {
        return TeacherResource::class;
    }

    protected function with(): array
    {
        return ['subjects'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'user_id' => ['nullable', 'integer', TenantRules::exists('users'), TenantRules::unique('teachers', 'user_id', $model?->getKey())],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'first_name_latin' => ['nullable', 'string', 'max:255'],
            'last_name_latin' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', Rule::enum(\App\Enums\Gender::class)],
            'phone' => ['nullable', 'string', 'regex:'.TenantRules::PHONE],
            'email' => ['nullable', 'email', 'max:255'],
            'specialization' => ['nullable', 'string', 'max:255'],
            'hired_on' => ['nullable', 'date'],
            'is_active' => ['boolean'],
            'subject_ids' => ['array', 'max:50'],
            'subject_ids.*' => ['integer', 'distinct', TenantRules::exists('subjects')],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        $subjectIds = $data['subject_ids'] ?? null;
        unset($data['subject_ids']);

        /** @var Teacher $teacher */
        $teacher = parent::persist($data, $model);

        if ($subjectIds !== null) {
            $tenantId = app(TenantContext::class)->id();
            $teacher->subjects()->sync(collect($subjectIds)->mapWithKeys(
                fn (int $id): array => [$id => ['tenant_id' => $tenantId]]
            )->all());
        }

        return $teacher;
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->query('q'), fn (Builder $b, string $q) => $b->where(
            fn (Builder $w) => $w->where('first_name', 'like', $this->like($q))
                ->orWhere('last_name', 'like', $this->like($q))
                ->orWhere('phone', 'like', $this->like($q))
        ));
    }
}
