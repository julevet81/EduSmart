<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\FeePlan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeePlan */
final class FeePlanResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'subject_id' => $this->subject_id,
            'name' => $this->name,
            'billing_mode' => $this->billing_mode->value,
            'amount' => $this->amount,
            'due_day' => $this->due_day,
            'bill_absences' => $this->bill_absences,
            'is_active' => $this->is_active,
        ];
    }
}
