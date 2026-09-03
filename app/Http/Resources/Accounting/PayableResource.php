<?php

namespace App\Http\Resources\Accounting;

use App\Enum\Accounting\PayableStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * The bill, its supplier and its due/settlement position as shown to staff.
 */
class PayableResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $today = Carbon::today();
        $due = Carbon::parse($this->due_date)->startOfDay();
        $daysOverdue = $this->status === PayableStatusEnum::Open && $due->lt($today)
            ? (int) $due->diffInDays($today)
            : 0;

        return [
            'id' => $this->id,
            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->supplier?->name,
            'bill_no' => $this->bill_no,
            'category' => $this->expense?->category?->value,
            'branch_id' => $this->expense?->branch_id,
            'amount' => round((float) ($this->expense?->amount ?? 0), 2),
            'vat_amount' => round((float) ($this->expense?->vat_amount ?? 0), 2),
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status?->value,
            'paid_at' => $this->paid_at?->toISOString(),
            'paid_via' => $this->paid_via?->value,
            'days_overdue' => $daysOverdue,
            'description' => $this->expense?->description,
            'recurring_bill_id' => $this->recurring_bill_id,
        ];
    }
}
