<?php

namespace App\Models;

use App\Enum\Tenancy\FeatureCategoryEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Spatie\Translatable\HasTranslations;

/**
 * A capability that a subscription can grant or withhold.
 *
 * This catalogue is the single source behind the admin feature switches, the plan
 * feature keys and the requireFeature gate; core rows are excluded from gating
 * entirely and stay enabled even when a subscription lapses.
 */
class Feature extends BaseModel
{
    use HasFactory, HasTranslations;

    public array $translatable = ['name'];

    protected $fillable = [
        'key',
        'name',
        'category',
        'is_core',
        'sort_order',
    ];

    protected $casts = [
        'category' => FeatureCategoryEnum::class,
        'is_core' => 'boolean',
        'sort_order' => 'integer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
    public function scopeCore(Builder $query): Builder
    {
        return $query->where('is_core', true);
    }

    /**
     * Features a plan may switch on or off. Core features are never gated.
     */
    public function scopeGated(Builder $query): Builder
    {
        return $query->where('is_core', false);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('key');
    }
}
