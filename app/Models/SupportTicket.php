<?php

namespace App\Models;

use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A support ticket a tenant raised with the platform.
 */
class SupportTicket extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'subject',
        'category',
        'status',
        'priority',
        'created_by_id',
        'assigned_to_id',
        'last_reply_at',
    ];

    protected $casts = [
        'status' => SupportTicketStatusEnum::class,
        'priority' => SupportPriorityEnum::class,
        'last_reply_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * Tickets still needing someone: settled ones are out of the queue.
     */
    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SupportTicketStatusEnum::Open->value,
            SupportTicketStatusEnum::Pending->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class, 'ticket_id');
    }

    /**
     * The message that decides who is being waited on. Eager-loaded as a relation so the
     * inbox can answer that for a page of tickets in one query.
     */
    public function lastMessage(): HasMany
    {
        return $this->messages()->latest('id')->limit(1);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_id');
    }
}
