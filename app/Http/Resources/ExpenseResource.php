<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Expense */
final class ExpenseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category->name),
            'description' => $this->description,
            'supplier' => $this->supplier,
            'amount' => $this->amount,
            'method' => $this->method->value,
            'reference' => $this->reference,
            'paid_on' => (string) $this->paid_on,
            'status' => $this->status->value,
            'note' => $this->note,
            'reverse_reason' => $this->reverse_reason,
            'reversed_at' => $this->reversed_at?->toIso8601String(),
        ];
    }
}
