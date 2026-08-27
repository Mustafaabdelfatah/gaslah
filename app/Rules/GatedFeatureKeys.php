<?php

namespace App\Rules;

use App\Models\Feature;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Every key of a feature-override map must name a gated feature.
 *
 * A core feature is part of the base product and cannot be switched off per tenant, so an
 * override naming one is rejected outright rather than silently dropped — the operator
 * learns the switch had no effect.
 */
class GatedFeatureKeys implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        $gated = Feature::query()->where('is_core', false)->pluck('key')->all();
        $unknown = array_diff(array_keys($value), $gated);

        if ($unknown !== []) {
            $fail(__('validation.gated_feature_keys', ['keys' => implode(', ', $unknown)]));
        }
    }
}
