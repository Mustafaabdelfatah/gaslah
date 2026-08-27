<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Enum\Affiliate\AffiliateReferralStatusEnum;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Affiliate\AffiliateReferralResource;
use App\Models\AffiliateReferral;
use Illuminate\Http\JsonResponse;

/**
 * The affiliate panel: profile with commission stats, and the referral log.
 */
class AffiliateController extends AffiliateBaseController
{
    public function me(): JsonResponse
    {
        $affiliate = $this->affiliate();
        $referrals = $affiliate->referrals()->get(['status', 'commission']);

        // Converted excludes cancelled referrals (a proxy until the platform subscription
        // entity lands in Phase 9, where converted = referred org has an ACTIVE plan).
        $active = $referrals->where('status', '!=', AffiliateReferralStatusEnum::Cancelled);

        return successResponse([
            'profile' => $affiliate->only('id', 'name', 'code', 'commission_type', 'commission_rate'),
            'stats' => [
                'referrals' => $referrals->count(),
                'converted' => $active->count(),
                'pending' => round((float) $referrals->whereIn('status', [AffiliateReferralStatusEnum::Pending, AffiliateReferralStatusEnum::Approved])->sum('commission'), 2),
                'paid' => round((float) $referrals->where('status', AffiliateReferralStatusEnum::Paid)->sum('commission'), 2),
                'total' => round((float) $active->sum('commission'), 2),
            ],
        ]);
    }

    public function referrals(PageRequest $request): JsonResponse
    {
        $query = AffiliateReferral::query()
            ->where('affiliate_id', $this->affiliate()->getKey())
            ->with('organization:id,name')
            ->latest('id');

        return successResponse(wrapPaginate($query, AffiliateReferralResource::class));
    }
}
