<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One side of a journal entry.
 */
class JournalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'account' => $this->whenLoaded('account', fn () => $this->account === null ? null : [
                'id' => $this->account->id,
                'code' => $this->account->code,
                'name' => $this->account->name,
            ]),
            'debit' => $this->debit,
            'credit' => $this->credit,
            'branch_id' => $this->branch_id,
            'memo' => $this->memo,
        ];
    }
}
