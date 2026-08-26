<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Enum\Affiliate\AffiliateReferralStatusEnum;
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

    public function referrals(): JsonResponse
    {
        $referrals = $this->affiliate()->referrals()
            ->with('organization:id,name')
            ->latest('id')
            ->limit(100)
            ->get();

        $data = $referrals->map(fn (AffiliateReferral $r) => [
            'id' => $r->getKey(),
            'organization' => $r->organization?->name,
            'plan_name' => $r->plan_name,
            'sub_amount' => round((float) $r->sub_amount, 2),
            'commission' => round((float) $r->commission, 2),
            'status' => $r->status->value,
            'paid_at' => $r->paid_at,
            'created_at' => $r->created_at,
        ]);

        return successResponse($data);
    }
}
