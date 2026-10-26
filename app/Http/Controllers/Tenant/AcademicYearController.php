<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\AcademicYearResource;
use App\Models\AcademicYear;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class AcademicYearController extends ResourceController
{
    protected function model(): string
    {
        return AcademicYear::class;
    }

    protected function resource(): string
    {
        return AcademicYearResource::class;
    }

    protected function with(): array
    {
        return ['terms'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => ['required', 'string', 'max:50', TenantRules::unique('academic_years', 'name', $model?->getKey())],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'is_current' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        $year = parent::persist($data, $model);

        // Exactly one current year per tenant.
        if (($data['is_current'] ?? false) === true) {
            AcademicYear::query()->whereKeyNot($year->getKey())->where('is_current', true)->update(['is_current' => false]);
        }

        return $year;
    }
}
