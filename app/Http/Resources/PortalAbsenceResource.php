<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Absence;
use App\Support\Schedule\Time;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Absence An absence as the family sees it (no internal ids beyond the lesson). */
final class PortalAbsenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'is_justified' => $this->is_justified,
            'reason' => $this->reason,
            'justification' => $this->justification,
            'date' => $this->lesson?->date,
            'starts_at' => $this->lesson === null ? null : Time::short($this->lesson->starts_at),
            'ends_at' => $this->lesson === null ? null : Time::short($this->lesson->ends_at),
            'subject' => $this->lesson?->subject?->name,
        ];
    }
}
