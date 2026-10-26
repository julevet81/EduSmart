<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\ScheduleSlot;
use App\Support\Schedule\Time;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScheduleSlot */
final class ScheduleSlotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'academic_year_id' => $this->academic_year_id,
            'weekday' => $this->weekday,
            'starts_at' => Time::short($this->starts_at),
            'ends_at' => Time::short($this->ends_at),
            'valid_from' => $this->valid_from,
            'valid_until' => $this->valid_until,
            'is_active' => $this->is_active,
            'group' => $this->whenLoaded('group', fn () => ['id' => $this->group->id, 'name' => $this->group->name]),
            'subject' => $this->whenLoaded('subject', fn () => ['id' => $this->subject->id, 'code' => $this->subject->code, 'name' => $this->subject->name]),
            'teacher' => $this->whenLoaded('teacher', fn () => ['id' => $this->teacher->id, 'first_name' => $this->teacher->first_name, 'last_name' => $this->teacher->last_name]),
            'room' => $this->whenLoaded('room', fn () => $this->room ? ['id' => $this->room->id, 'name' => $this->room->name] : null),
        ];
    }
}
