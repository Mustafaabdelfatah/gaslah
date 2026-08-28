<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use App\Models\Feature;
use Illuminate\Validation\Rule;

/**
 * Selling a tenant a capability above its plan.
 *
 * Only a gated feature can be sold: core features are part of the base product, so an
 * add-on for one would be charging for something the tenant already has.
 */
class StoreOrgAddonRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'key' => [
                'required', 'string', 'max:60',
                // A closure, not ->where('is_core', false): the value form stringifies
                // false to an empty string, and the constraint then matches nothing.
                Rule::exists('features', 'key')->where(fn ($query) => $query->where('is_core', false)),
            ],
            'price_monthly' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
            // Null runs until it is switched off, which is the normal case for an add-on
            // billed alongside the subscription.
            'expires_at' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function key(): string
    {
        return $this->string('key')->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function addon(): array
    {
        return [
            'is_active' => ! $this->has('is_active') || $this->boolean('is_active'),
            'price_monthly' => (float) $this->input('price_monthly', 0),
            'expires_at' => $this->input('expires_at'),
        ];
    }

    /**
     * The gated keys an operator may sell, for the error message and the form.
     *
     * @return array<int, string>
     */
    public static function sellableKeys(): array
    {
        return Feature::query()->where('is_core', false)->pluck('key')->all();
    }
}
