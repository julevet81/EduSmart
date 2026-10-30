<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TenantStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // guarded by the "platform" middleware
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'plan' => ['sometimes', 'nullable', 'string', Rule::exists('plans', 'code')],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^(?:\+213|0)[1-9]\d{7,8}$/'],
            'trial_ends_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
