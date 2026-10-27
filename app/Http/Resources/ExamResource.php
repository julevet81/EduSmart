<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Exam;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Exam */
final class ExamResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'term_id' => $this->term_id,
            'group_id' => $this->group_id,
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => ['id' => $this->subject->id, 'code' => $this->subject->code, 'name' => $this->subject->name]),
            'teacher_id' => $this->teacher_id,
            'teacher' => $this->whenLoaded('teacher', fn () => $this->teacher === null ? null
                : ['id' => $this->teacher->id, 'name' => $this->teacher->first_name.' '.$this->teacher->last_name]),
            'name' => $this->name,
            'kind' => $this->kind->value,
            'date' => (string) $this->date,
            'max_score' => $this->max_score,
            'coefficient' => $this->coefficient,
            'is_locked' => $this->is_locked,
            'grades_count' => $this->whenCounted('grades'),
        ];
    }
}
