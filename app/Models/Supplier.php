<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A supplier, scoped to an organization.
 */
class Supplier extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'phone',
        'email',
        'address',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function payables(): HasMany
    {
        return $this->hasMany(Payable::class);
    }

    public function recurringBills(): HasMany
    {
        return $this->hasMany(RecurringBill::class);
    }
}
