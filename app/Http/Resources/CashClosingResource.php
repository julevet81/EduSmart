<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CashClosing;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashClosing */
final class CashClosingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'closing_date' => (string) $this->closing_date,
            'period_start' => $this->period_start === null ? null : (string) $this->period_start,
            'opening_balance' => $this->opening_balance,
            'cash_in' => $this->cash_in,
            'cash_out' => $this->cash_out,
            'expected_balance' => $this->expected_balance,
            'counted_balance' => $this->counted_balance,
            'difference' => $this->difference,
            'note' => $this->note,
        ];
    }
}
