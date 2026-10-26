<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Guardian;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Guardian */
final class GuardianResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $canSeeSensitive = (bool) $request->user()?->can('students.view_medical');

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'occupation' => $this->occupation,
            'address' => $this->address,
            'national_id' => $this->when($canSeeSensitive, fn () => $this->national_id),
            // Present only when listed through a student (pivot data).
            'relationship' => $this->whenPivotLoaded('guardian_student', fn () => $this->pivot->relationship),
            'is_primary' => $this->whenPivotLoaded('guardian_student', fn () => (bool) $this->pivot->is_primary),
            'is_financial_responsible' => $this->whenPivotLoaded('guardian_student', fn () => (bool) $this->pivot->is_financial_responsible),
        ];
    }
}
