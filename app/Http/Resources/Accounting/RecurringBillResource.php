<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A recurring bill/expense template and its generation progress.
 */
class RecurringBillResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category?->value,
            'amount' => round((float) $this->amount, 2),
            'vat_amount' => round((float) $this->vat_amount, 2),
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->branch?->name,
            'paid_from' => $this->paid_from?->value,
            'frequency' => $this->frequency?->value,
            'anchor_day' => $this->anchor_day,
            'due_days' => $this->due_days,
            'next_run' => $this->next_run?->toDateString(),
            'last_run' => $this->last_run?->toDateString(),
            'generated_count' => $this->generated_count,
            'is_active' => $this->is_active,
            'description' => $this->description,
        ];
    }
}
