<?php

namespace App\Http\Resources\Audit;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the tenant's audit trail.
 *
 * Before/after snapshots are carried in full: the point of an audit record is to show
 * exactly what changed, and summarising it would defeat the reason it is kept.
 */
class AuditEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // This spatie version keeps the before/after snapshots in attribute_changes;
        // properties is the free-form bag a caller may attach alongside them.
        $changes = $this->attribute_changes ?? collect();

        return [
            'id' => $this->id,
            'entity' => $this->log_name,
            'action' => $this->event,
            'description' => $this->description,

            'subject_type' => $this->subject_type === null ? null : class_basename($this->subject_type),
            'subject_id' => $this->subject_id,

            'causer_id' => $this->causer_id,
            'causer' => $this->whenLoaded('causer', fn () => $this->causer?->name),

            'before' => data_get($changes, 'old'),
            'after' => data_get($changes, 'attributes'),

            'created_at' => $this->created_at,
        ];
    }
}
