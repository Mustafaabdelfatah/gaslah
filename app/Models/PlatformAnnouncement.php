<?php

namespace App\Models;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform → tenant broadcast banner shown inside tenant dashboards. A null
 * organization targets every tenant; a value scopes the banner to one.
 */
class PlatformAnnouncement extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'title',
        'body',
        'level',
        'organization_id',
        'is_active',
        'starts_at',
        'ends_at',
        'created_by_id',
    ];

    protected $casts = [
        'level' => PlatformAnnouncementLevelEnum::class,
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    /**
     * Announcements currently visible to a given organization: active, within their
     * window, and targeting either every tenant or this one.
     */
    public function scopeVisibleTo(Builder $query, int $organizationId): Builder
    {
        $now = Carbon::now();

        return $query->where('is_active', true)
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);
            })
            ->where(function (Builder $q) use ($organizationId) {
                $q->whereNull('organization_id')->orWhere('organization_id', $organizationId);
            });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
