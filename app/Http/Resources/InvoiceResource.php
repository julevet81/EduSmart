<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Invoice */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'student_id' => $this->student_id,
            'student' => $this->whenLoaded('student', fn () => [
                'id' => $this->student->id,
                'registration_number' => $this->student->registration_number,
                'name' => $this->student->first_name.' '.$this->student->last_name,
            ]),
            'description' => $this->description,
            'period_key' => $this->period_key,
            'issue_date' => (string) $this->issue_date,
            'due_date' => (string) $this->due_date,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'discount_amount' => $this->discount_amount,
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'outstanding' => $this->outstanding(),
            'status' => $this->status->value,
            'is_overdue' => $this->isOverdue(),
            'void_reason' => $this->void_reason,
            'voided_at' => $this->voided_at?->toIso8601String(),
        ];
    }
}
