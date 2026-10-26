<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\StaffMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin StaffMember */
final class StaffMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'first_name' => $this->first_name, 'last_name' => $this->last_name,
            'position' => $this->position, 'phone' => $this->phone, 'is_active' => $this->is_active,
        ];
    }
}
