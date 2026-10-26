<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SalaryContract;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SalaryContract */
final class SalaryContractResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payee_type' => $this->payee_type->value,
            'payee_id' => $this->payee_id,
            'type' => $this->type->value,
            'amount' => $this->amount,
            'starts_on' => (string) $this->starts_on,
            'ends_on' => $this->ends_on === null ? null : (string) $this->ends_on,
            'is_active' => $this->is_active,
        ];
    }
}
