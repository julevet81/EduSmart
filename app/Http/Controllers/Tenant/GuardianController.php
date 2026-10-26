<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\GuardianResource;
use App\Models\Guardian;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class GuardianController extends ResourceController
{
    protected function model(): string
    {
        return Guardian::class;
    }

    protected function resource(): string
    {
        return GuardianResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'user_id' => ['nullable', 'integer', TenantRules::exists('users'), TenantRules::unique('guardians', 'user_id', $model?->getKey())],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.TenantRules::PHONE],
            'email' => ['nullable', 'email', 'max:255'],
            'occupation' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:64'],
        ];
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
