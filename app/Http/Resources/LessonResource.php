<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Lesson;
use App\Support\Schedule\Time;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lesson */
final class LessonResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'schedule_slot_id' => $this->schedule_slot_id,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'date' => $this->date,
            'starts_at' => Time::short($this->starts_at),
            'ends_at' => Time::short($this->ends_at),
            'is_customized' => $this->is_customized,
            'note' => $this->note,
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
            'subject' => $this->whenLoaded('subject', fn () => ['id' => $this->subject->id, 'code' => $this->subject->code, 'name' => $this->subject->name]),
            'teacher' => $this->whenLoaded('teacher', fn () => ['id' => $this->teacher->id, 'first_name' => $this->teacher->first_name, 'last_name' => $this->teacher->last_name]),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'name' => $this->room->name] : null),
        ];
    }
}
