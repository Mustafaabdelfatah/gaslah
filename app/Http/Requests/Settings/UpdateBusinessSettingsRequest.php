<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The organization's own commercial profile: what appears on its invoices, the tax it
 * charges, and its brand colours.
 *
 * The optional text columns are emptied to null rather than stored as "", so a blank field
 * reads the same however it was cleared.
 */
class UpdateBusinessSettingsRequest extends TenantFormRequest
{
    /**
     * Receipt paper this product can actually print on, in millimetres.
     */
    private const RECEIPT_WIDTHS = [58, 80];

    /**
     * Six-digit hex, the only form the clients render.
     */
    private const HEX_COLOUR = 'regex:/^#[0-9a-fA-F]{6}$/';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'default_currency' => ['required', 'string', 'min:1', 'max:10'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'receipt_enabled' => ['required', 'boolean'],
            'receipt_width' => ['required', 'integer', 'in:'.implode(',', self::RECEIPT_WIDTHS)],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'cr_number' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'max:40'],
            'receipt_footer' => ['nullable', 'string', 'max:500'],

            'brand_primary' => ['nullable', 'string', self::HEX_COLOUR],
            'brand_accent' => ['nullable', 'string', self::HEX_COLOUR],
        ];
    }

    /**
     * The organization's attributes, with blank optional fields normalised to null and the
     * colours lower-cased so `#AABBCC` and `#aabbcc` never both end up stored.
     *
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $validated = $this->validated();

        foreach (['phone', 'email', 'address', 'cr_number', 'vat_number', 'receipt_footer'] as $field) {
            $validated[$field] = $this->blankToNull($validated[$field] ?? null);
        }

        foreach (['brand_primary', 'brand_accent'] as $field) {
            $colour = $this->blankToNull($validated[$field] ?? null);
            $validated[$field] = $colour === null ? null : mb_strtolower($colour);
        }

        return $validated;
    }

    private function blankToNull(?string $value): ?string
    {
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
