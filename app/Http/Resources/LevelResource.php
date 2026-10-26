<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Level;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Level */
final class LevelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'name_fr' => $this->name_fr,
            'cycle' => $this->cycle->value, 'sort_order' => $this->sort_order, 'is_active' => $this->is_active,
        ];
    }
}
