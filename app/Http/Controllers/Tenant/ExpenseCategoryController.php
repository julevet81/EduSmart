<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\ExpenseCategoryResource;
use App\Models\ExpenseCategory;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class ExpenseCategoryController extends ResourceController
{
    protected function model(): string
    {
        return ExpenseCategory::class;
    }

    protected function resource(): string
    {
        return ExpenseCategoryResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => ['required', 'string', 'max:100', TenantRules::unique('expense_categories', 'name', $model?->getKey())],
            'is_active' => ['boolean'],
        ];
    }
}
