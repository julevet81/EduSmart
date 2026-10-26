<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesTenantId;
use App\Support\Rbac\RoleBlueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreUserRequest extends FormRequest
{
    use ResolvesTenantId;

    public function authorize(): bool
    {
        return true; // permission is enforced by the route middleware (can:users.create)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $this->tenantId()),
            ],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+213|0)[1-9]\d{7,8}$/'],
            'password' => ['required', 'string', Password::defaults()],
            'is_active' => ['sometimes', 'boolean'],
            'roles' => ['sometimes', 'array', 'max:10'],
            'roles.*' => [
                'string',
                Rule::exists('roles', 'name')
                    ->where('tenant_id', $this->tenantId())
                    ->where('guard_name', RoleBlueprint::GUARD),
            ],
        ];
    }
}
