<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesTenantId;
use App\Models\User;
use App\Support\Rbac\RoleBlueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateUserRequest extends FormRequest
{
    use ResolvesTenantId;

    public function authorize(): bool
    {
        return true; // permission is enforced by the route middleware (can:users.update)
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var User $target */
        $target = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => [
                'sometimes', 'string', 'email', 'max:255',
                Rule::unique('users', 'email')->where('tenant_id', $this->tenantId())->ignore($target->getKey()),
            ],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\+213|0)[1-9]\d{7,8}$/'],
            'password' => ['sometimes', 'string', Password::defaults()],
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
