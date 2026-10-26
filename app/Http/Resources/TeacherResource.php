<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Teacher */
final class TeacherResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'first_name_latin' => $this->first_name_latin,
            'last_name_latin' => $this->last_name_latin,
            'gender' => $this->gender?->value,
            'phone' => $this->phone,
            'email' => $this->email,
            'specialization' => $this->specialization,
            'hired_on' => $this->hired_on?->toDateString(),
            'is_active' => $this->is_active,
            'subjects' => $this->whenLoaded('subjects', fn () => SubjectResource::collection($this->subjects)),
        ];
    }
}
