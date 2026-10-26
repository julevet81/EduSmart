<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Group */
final class GroupResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'capacity' => $this->capacity,
            'is_active' => $this->is_active,
            'academic_year_id' => $this->academic_year_id,
            'level' => $this->whenLoaded('level', fn () => new LevelResource($this->level)),
            'track' => $this->whenLoaded('track', fn () => $this->track ? new TrackResource($this->track) : null),
            'room' => $this->whenLoaded('room', fn () => $this->room ? new RoomResource($this->room) : null),
            'students_count' => $this->whenCounted('activeEnrollments'),
        ];
    }
}
