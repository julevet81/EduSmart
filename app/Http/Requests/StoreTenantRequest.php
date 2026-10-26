<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\Tenants\NewTenantData;
use App\Enums\TenantType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the "platform" middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'min:3', 'max:63',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('saas.tenancy.reserved_slugs')),
                Rule::unique('tenants', 'slug'),
            ],
            'type' => ['required', Rule::enum(TenantType::class)],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:/^(?:\+213|0)[1-9]\d{7,8}$/'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', Password::defaults()],
        ];
    }

    public function toData(): NewTenantData
    {
        $v = $this->validated();

        return new NewTenantData(
            name: $v['name'],
            slug: $v['slug'],
            type: TenantType::from($v['type']),
            contactEmail: $v['contact_email'] ?? null,
            phone: $v['phone'] ?? null,
            ownerName: $v['owner_name'],
            ownerEmail: $v['owner_email'],
            ownerPassword: $v['owner_password'],
        );
    }
}
