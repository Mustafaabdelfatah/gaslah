<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformPartnerRequest;
use App\Http\Requests\Platform\StoreDistributionRequest;
use App\Http\Resources\Platform\PartnerDistributionResource;
use App\Http\Resources\Platform\PartnerOptionResource;
use App\Http\Resources\Platform\PlatformPartnerResource;
use App\Models\PlatformPartner;
use App\Models\PlatformPartnerDistribution;
use App\Models\User;
use App\Services\Platform\PlatformPartnerService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Founding partners: stakes, profit shares and payouts.
 *
 * Sensitive throughout — even reading needs manage_partners, because ownership
 * percentages and what each partner is owed are not for ordinary platform staff. The one
 * exception is options(), which returns names only and feeds the expense form, so it
 * settles for manage_accounting.
 */
class AdminPartnerController extends BaseController
{
    public function __construct(private readonly PlatformPartnerService $partners)
    {
        parent::__construct();
    }

    /**
     * Every partner with their share, what has been paid, and what remains owed.
     */
    public function index(): JsonResponse
    {
        $overview = $this->partners->overview();

        return successResponse([
            'net_income' => $overview['net_income'],
            'ownership_ceiling' => $overview['ownership_ceiling'],
            'allocated_ownership' => $overview['allocated_ownership'],
            'partners' => $overview['partners']->map(
                fn (array $row) => new PlatformPartnerResource($row['partner'], [
                    'share' => $row['share'],
                    'distributed' => $row['distributed'],
                    'outstanding_reimbursement' => $row['outstanding_reimbursement'],
                    'net_owed' => $row['net_owed'],
                ]),
            ),
        ]);
    }

    /**
     * Names only, for the expense form's "paid by" picker — no money, no ownership.
     */
    public function options(): JsonResponse
    {
        $partners = PlatformPartner::query()->active()->orderBy('name')->get(['id', 'name']);

        return successResponse(PartnerOptionResource::collection($partners));
    }

    public function store(PlatformPartnerRequest $request): JsonResponse
    {
        $partner = $this->partners->save($request->validated());

        return successResponse(new PlatformPartnerResource($partner), __('api.created_success'), Response::HTTP_CREATED);
    }

    public function update(PlatformPartnerRequest $request, PlatformPartner $partner): JsonResponse
    {
        $partner = $this->partners->save($request->validated(), $partner);

        return successResponse(new PlatformPartnerResource($partner), __('api.updated_success'));
    }

    public function distributions(PageRequest $request, PlatformPartner $partner): JsonResponse
    {
        $query = PlatformPartnerDistribution::query()
            ->where('partner_id', $partner->getKey())
            ->with('partner:id,name')
            ->latest('date');

        return successResponse(wrapPaginate($query, PartnerDistributionResource::class));
    }

    public function distribute(StoreDistributionRequest $request, PlatformPartner $partner): JsonResponse
    {
        /** @var User $admin */
        $admin = request()->user();

        $distribution = $this->partners->distribute(
            $partner,
            $request->amount(),
            $request->paidOn(),
            $request->note(),
            $admin->getKey(),
        );

        return successResponse(
            new PartnerDistributionResource($distribution->load('partner:id,name')),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }
}
