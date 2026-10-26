<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Enrollment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Enrollment */
final class EnrollmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'student_id' => $this->student_id,
            'group_id' => $this->group_id,
            'academic_year_id' => $this->academic_year_id,
            'status' => $this->status->value,
            'enrolled_on' => $this->enrolled_on->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'notes' => $this->notes,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'registration_number' => $this->student->registration_number,
                'first_name' => $this->student->first_name,
                'last_name' => $this->student->last_name,
            ]),
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
        ];
    }
}
