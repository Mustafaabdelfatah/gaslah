<?php

namespace App\Scopes\Tenancy;

use Illuminate\Database\Eloquent\Builder;

trait OrganizationScopes
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_suspended', false)->whereNull('archived_at');
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('is_suspended', true);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }
}
