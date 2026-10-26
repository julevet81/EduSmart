<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Student */
final class StudentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // National ID and medical notes need an explicit permission.
        $canSeeSensitive = (bool) $request->user()?->can('students.view_medical');

        return [
            'id' => $this->id,
            'registration_number' => $this->registration_number,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'first_name_latin' => $this->first_name_latin,
            'last_name_latin' => $this->last_name_latin,
            'gender' => $this->gender?->value,
            'birth_date' => $this->birth_date?->toDateString(),
            'birth_place' => $this->birth_place,
            'birth_certificate_number' => $this->birth_certificate_number,
            'wilaya_code' => $this->wilaya_code,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => $this->is_active,
            'national_id' => $this->when($canSeeSensitive, fn () => $this->national_id),
            'medical_notes' => $this->when($canSeeSensitive, fn () => $this->medical_notes),
            'guardians' => $this->whenLoaded('guardians', fn () => GuardianResource::collection($this->guardians)),
            'current_enrollment' => $this->whenLoaded('currentEnrollment', fn () => $this->currentEnrollment
                ? new EnrollmentResource($this->currentEnrollment) : null),
        ];
    }
}
