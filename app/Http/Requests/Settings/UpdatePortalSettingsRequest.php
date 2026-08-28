<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * The organization's customer-portal identity: how it is reached, how it looks, and the
 * documents it links to.
 */
class UpdatePortalSettingsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // An absolute http(s) URL or a root-relative upload path. Anything else — a
            // `javascript:` or `data:` URI above all — would be rendered by the portal.
            'logo_url' => ['nullable', 'string', 'max:500', 'regex:#^(https?://|/)#'],

            'slug' => [
                'nullable', 'string', 'min:2', 'max:40', 'regex:/^[a-z0-9_-]+$/',
                Rule::unique('organizations', 'slug')->ignore($this->organizationId()),
            ],

            'custom_domain' => ['nullable', 'string', 'max:200', 'regex:/^[a-z0-9.-]+$/'],

            'show_offers' => ['required', 'boolean'],
            'terms_url' => ['nullable', 'url:http,https', 'max:500'],
            'privacy_url' => ['nullable', 'url:http,https', 'max:500'],
        ];
    }

    /**
     * Lower-case the two fields a customer might type in any case before they are matched
     * or stored, so validation and persistence agree on one form.
     */
    protected function prepareForValidation(): void
    {
        foreach (['slug', 'custom_domain'] as $field) {
            if ($this->filled($field)) {
                $this->merge([$field => mb_strtolower(trim($this->string($field)->toString()))]);
            }
        }
    }

    /**
     * A cleared slug means "leave it alone", not "remove it": a portal link a customer has
     * bookmarked must not break because the field was blanked on an unrelated save.
     */
    public function slug(): ?string
    {
        return $this->filled('slug') ? $this->string('slug')->toString() : null;
    }

    public function logoUrl(): ?string
    {
        return $this->filled('logo_url') ? $this->string('logo_url')->toString() : null;
    }

    public function customDomain(): ?string
    {
        return $this->filled('custom_domain') ? $this->string('custom_domain')->toString() : null;
    }

    /**
     * @return array{show_offers: bool, terms_url: ?string, privacy_url: ?string}
     */
    public function portalConfig(): array
    {
        return [
            'show_offers' => $this->boolean('show_offers'),
            'terms_url' => $this->filled('terms_url') ? $this->string('terms_url')->trim()->toString() : null,
            'privacy_url' => $this->filled('privacy_url') ? $this->string('privacy_url')->trim()->toString() : null,
        ];
    }
}
