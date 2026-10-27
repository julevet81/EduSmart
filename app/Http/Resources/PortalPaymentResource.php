<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment A receipt as the family sees it: no staff identity, no internal notes. */
final class PortalPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'amount' => $this->amount,
            'method' => $this->method->value,
            'reference' => $this->reference,
            'paid_on' => (string) $this->paid_on,
            'status' => $this->status->value,
        ];
    }
}
