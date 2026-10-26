<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $allocated = $this->relationLoaded('allocations')
            ? (int) $this->allocations->whereNull('reversed_at')->sum('amount') : null;

        return [
            'id' => $this->id,
            'receipt_number' => $this->receipt_number,
            'student_id' => $this->student_id,
            'guardian_id' => $this->guardian_id,
            'amount' => $this->amount,
            'method' => $this->method->value,
            'reference' => $this->reference,
            'paid_on' => (string) $this->paid_on,
            'status' => $this->status->value,
            'note' => $this->note,
            'received_by' => $this->received_by,
            'reverse_reason' => $this->reverse_reason,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
            'unallocated' => $allocated === null ? null : ($this->status->value === 'valid' ? $this->amount - $allocated : 0),
            'allocations' => $this->whenLoaded('allocations', fn () => $this->allocations->map(fn ($a): array => [
                'invoice_id' => $a->invoice_id, 'amount' => $a->amount, 'reversed' => $a->reversed_at !== null,
            ])->values()),
        ];
    }
}
