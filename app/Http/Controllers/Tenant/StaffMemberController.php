<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\StaffMemberResource;
use App\Models\StaffMember;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class StaffMemberController extends ResourceController
{
    protected function model(): string
    {
        return StaffMember::class;
    }

    protected function resource(): string
    {
        return StaffMemberResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'regex:'.TenantRules::PHONE],
            'is_active' => ['boolean'],
        ];
    }

    protected function filter(Builder $query, Request $request): void
    {
        $query->when($request->query('q'), fn (Builder $q, $v) => $q
            ->where(fn (Builder $w) => $w->where('first_name', 'like', $this->like((string) $v))->orWhere('last_name', 'like', $this->like((string) $v))));
    }
}
