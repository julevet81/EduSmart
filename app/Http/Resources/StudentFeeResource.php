<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StudentFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StudentFee */
final class StudentFeeResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'fee_plan_id' => $this->fee_plan_id,
            'plan' => $this->whenLoaded('plan', fn () => new FeePlanResource($this->plan)),
            'discount_type' => $this->discount_type?->value,
            'discount_value' => $this->discount_value,
            'discount_reason' => $this->discount_reason,
            'starts_on' => (string) $this->starts_on,
            'ends_on' => $this->ends_on === null ? null : (string) $this->ends_on,
            'is_active' => $this->is_active,
        ];
    }
}
