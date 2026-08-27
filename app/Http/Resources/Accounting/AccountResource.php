<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chart-of-accounts entry.
 *
 * `is_locked` tells the UI to disable the structural fields: a system account is resolved
 * by its key throughout the ledger and every report, so its code and type cannot move.
 */
class AccountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'type' => $this->type,
            'parent_id' => $this->parent_id,

            'is_system' => (bool) $this->is_system,
            'system_key' => $this->system_key,
            'is_locked' => $this->isStructurallyLocked(),
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at,
        ];
    }
}
