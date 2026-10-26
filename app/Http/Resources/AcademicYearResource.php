<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\AcademicYear;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AcademicYear */
final class AcademicYearResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'is_current' => $this->is_current,
            'terms' => $this->whenLoaded('terms', fn () => $this->terms->map(fn ($t) => [
                'id' => $t->id, 'number' => $t->number, 'name' => $t->name,
                'starts_on' => $t->starts_on->toDateString(), 'ends_on' => $t->ends_on->toDateString(),
            ])->values()),
        ];
    }
}
