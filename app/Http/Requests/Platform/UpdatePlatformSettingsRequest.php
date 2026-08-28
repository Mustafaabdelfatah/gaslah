<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A card of the operator's settings centre.
 *
 * Each group has its own fields, so the rules are chosen by the group in the path. Every
 * field is optional: the centre saves one card at a time and an absent key means "leave it
 * alone". An unknown group never reaches here — the controller answers 404 first.
 */
class UpdatePlatformSettingsRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return match ($this->route('group')) {
            'invoicing' => [
                // The platform's legal name and VAT number: they reach the ZATCA QR on
                // every invoice it issues, so shape matters.
                'sellerName' => ['nullable', 'string', 'max:200'],
                'sellerVat' => ['nullable', 'string', 'regex:/^3\d{13}3$/'],
            ],

            'partners' => [
                // Below 100% the platform holds some of itself back; above it would let
                // more be owned than exists.
                'ownershipCeiling' => ['nullable', 'numeric', 'min:0', 'max:100'],
            ],

            'announcements' => [
                'defaultLevel' => ['nullable', Rule::in(PlatformAnnouncementLevelEnum::values())],
                // How long a banner runs when the operator does not set an end date.
                'defaultDurationDays' => ['nullable', 'integer', 'min:1', 'max:365'],
                // How many banners a tenant's dashboard shows at once.
                'tenantNoticeLimit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ],

            'marketing' => [
                'defaultLeadSource' => ['nullable', 'string', 'max:60'],
            ],

            'support' => [
                // The list a tenant picks from when filing a ticket.
                'categories' => ['nullable', 'array', 'max:40'],
                'categories.*' => ['required', 'string', 'max:60'],

                // How long a ticket may sit unanswered before the inbox flags it.
                'slaResponseMinutes' => ['nullable', 'integer', 'min:1', 'max:10080'],

                'autoReplyEnabled' => ['nullable', 'boolean'],
                'autoReplyText' => ['nullable', 'string', 'max:1000'],
            ],

            default => [],
        };
    }

    /**
     * Only the keys actually sent, so a card that omits a field leaves it as it stands
     * rather than clearing it.
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        // validated() carries only the group's own declared fields, and only the ones the
        // request actually sent — which is exactly the merge the service wants.
        return $this->validated();
    }
}
