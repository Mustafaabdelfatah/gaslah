<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The accounting period lock for one organization.
 */
class BooksLock extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'closed_through',
        'updated_by_id',
    ];

    protected $casts = [
        'closed_through' => 'date',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }
}
