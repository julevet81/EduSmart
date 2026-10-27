<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SubjectCoefficient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SubjectCoefficient */
final class SubjectCoefficientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'level_id' => $this->level_id,
            'track_id' => $this->track_id,
            'subject_id' => $this->subject_id,
            'subject' => $this->whenLoaded('subject', fn () => ['id' => $this->subject->id, 'code' => $this->subject->code, 'name' => $this->subject->name]),
            'coefficient' => $this->coefficient,
        ];
    }
}
