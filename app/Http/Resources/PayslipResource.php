<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payslip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payslip */
final class PayslipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'payee_type' => $this->payee_type->value,
            'payee_id' => $this->payee_id,
            'payee_name' => $this->resource->getAttributes()['payee_name'] ?? null,
            'month' => $this->month,
            'fixed_amount' => $this->fixed_amount,
            'lessons_count' => $this->lessons_count,
            'lessons_minutes' => $this->lessons_minutes,
            'variable_amount' => $this->variable_amount,
            'bonus_amount' => $this->bonus_amount,
            'deduction_amount' => $this->deduction_amount,
            'advance_amount' => $this->advance_amount,
            'net_amount' => $this->net_amount,
            'status' => $this->status->value,
            'method' => $this->method?->value,
            'paid_on' => $this->paid_on === null ? null : (string) $this->paid_on,
            'void_reason' => $this->void_reason,
            'reverse_reason' => $this->reverse_reason,
        ];
    }
}
