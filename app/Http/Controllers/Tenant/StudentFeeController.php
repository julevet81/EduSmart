<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\DiscountType;
use App\Enums\EnrollmentStatus;
use App\Http\Resources\StudentFeeResource;
use App\Models\Enrollment;
use App\Models\FeePlan;
use App\Models\StudentFee;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Which fee plans a student pays, with their discount. Deleting is replaced by is_active=false / ends_on (history stays). */
final class StudentFeeController extends ResourceController
{
    protected function model(): string
    {
        return StudentFee::class;
    }

    protected function resource(): string
    {
        return StudentFeeResource::class;
    }

    protected function with(): array
    {
        return ['plan'];
    }

    protected function rules(Request $request, ?Model $model): array
    {
        $immutable = $model === null ? [
            'student_id' => ['required', 'integer', TenantRules::exists('students')],
            'fee_plan_id' => [
                'required', 'integer', TenantRules::exists('fee_plans'),
                TenantRules::unique('student_fees', 'fee_plan_id')->where('student_id', (int) $request->input('student_id')),
            ],
        ] : [];

        return $immutable + self::terms();
    }

    /** @return array<string, list<mixed>> */
    private static function terms(): array
    {
        return [
            'discount_type' => ['nullable', Rule::enum(DiscountType::class), 'required_with:discount_value'],
            'discount_value' => ['integer', 'min:0', 'max:100000000000'],
            'discount_reason' => ['nullable', 'string', 'max:255'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'is_active' => ['boolean'],
        ];
    }

    protected function persist(array $data, ?Model $model): Model
    {
        $type = $data['discount_type'] ?? $model?->discount_type?->value;
        $value = $data['discount_value'] ?? $model?->discount_value ?? 0;

        if ($type === DiscountType::Percent->value && $value > 100) {
            abort(422, 'A percentage discount cannot exceed 100.');
        }

        return parent::persist($data, $model);
    }

    protected function filter(Builder $query, Request $request): void
    {
        $request->validate(['student_id' => ['sometimes', 'integer'], 'fee_plan_id' => ['sometimes', 'integer']]);

        $query->when($request->query('student_id'), fn (Builder $q, $v) => $q->where('student_id', $v))
            ->when($request->query('fee_plan_id'), fn (Builder $q, $v) => $q->where('fee_plan_id', $v));
    }

    /** Assigns one plan to every actively enrolled student of a group (skips those who already have it). */
    public function assignGroup(Request $request, string $planId): JsonResponse
    {
        $plan = FeePlan::query()->findOrFail($planId);

        $data = $request->validate([
            'group_id' => ['required', 'integer', TenantRules::exists('groups')],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'discount_type' => ['nullable', Rule::enum(DiscountType::class), 'required_with:discount_value'],
            'discount_value' => ['integer', 'min:0', 'max:100000000000'],
        ]);

        $type = $data['discount_type'] ?? null;
        $value = (int) ($data['discount_value'] ?? 0);

        if ($type === DiscountType::Percent->value && $value > 100) {
            abort(422, 'A percentage discount cannot exceed 100.');
        }

        $created = DB::transaction(function () use ($plan, $data, $type, $value): int {
            $students = Enrollment::query()
                ->where('group_id', $data['group_id'])
                ->where('status', EnrollmentStatus::Active->value)
                ->pluck('student_id');

            $have = StudentFee::query()->where('fee_plan_id', $plan->getKey())->whereIn('student_id', $students)->pluck('student_id')->all();
            $count = 0;

            foreach ($students->diff($have) as $studentId) {
                StudentFee::query()->create([
                    'student_id' => $studentId, 'fee_plan_id' => $plan->getKey(), 'starts_on' => $data['starts_on'],
                    'discount_type' => $type, 'discount_value' => $value, 'is_active' => true,
                ]);
                $count++;
            }

            return $count;
        });

        return response()->json(['assigned' => $created]);
    }
}
