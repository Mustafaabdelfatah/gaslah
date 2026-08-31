<?php

namespace App\Models;

use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff member's declared monthly salary.
 *
 * Nothing is paid from this and no journal entry comes out of it — it is the figure
 * the owner states so a person's output can be weighed against what they cost.
 */
class EmployeeCost extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'organization_id',
        'user_id',
        'monthly_salary',
        'note',
    ];

    protected $casts = [
        'monthly_salary' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
