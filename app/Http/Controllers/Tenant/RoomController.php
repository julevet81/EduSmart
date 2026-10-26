<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Resources\RoomResource;
use App\Models\Room;
use App\Support\Validation\TenantRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

final class RoomController extends ResourceController
{
    protected function model(): string
    {
        return Room::class;
    }

    protected function resource(): string
    {
        return RoomResource::class;
    }

    protected function rules(Request $request, ?Model $model): array
    {
        return [
            'name' => ['required', 'string', 'max:100', TenantRules::unique('rooms', 'name', $model?->getKey())],
            'capacity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'is_active' => ['boolean'],
        ];
    }
}
