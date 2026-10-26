<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PayrollAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PayrollAdjustment */
final class PayrollAdjustmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payee_type' => $this->payee_type->value,
            'payee_id' => $this->payee_id,
            'kind' => $this->kind->value,
            'amount' => $this->amount,
            'method' => $this->method?->value,
            'effective_on' => (string) $this->effective_on,
            'note' => $this->note,
            'status' => $this->status->value,
            'payslip_id' => $this->payslip_id,
            'reverse_reason' => $this->reverse_reason,
        ];
    }
}
