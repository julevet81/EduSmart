<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Absence;
use App\Support\Schedule\Time;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Absence */
final class AbsenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lesson_id' => $this->lesson_id,
            'student_id' => $this->student_id,
            'type' => $this->type->value,
            'reason' => $this->reason,
            'is_justified' => $this->is_justified,
            'justification' => $this->justification,
            'justified_at' => $this->justified_at?->toIso8601String(),
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'registration_number' => $this->student->registration_number,
                'first_name' => $this->student->first_name,
                'last_name' => $this->student->last_name,
            ]),
            'lesson' => $this->whenLoaded('lesson', fn () => [
                'id' => $this->lesson->id,
                'date' => $this->lesson->date,
                'starts_at' => Time::short($this->lesson->starts_at),
                'ends_at' => Time::short($this->lesson->ends_at),
                'group_id' => $this->lesson->group_id,
                'subject_id' => $this->lesson->subject_id,
            ]),
        ];
    }
}
