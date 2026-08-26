<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\Organization;
use App\Models\PayoutSettlement;
use App\Services\Payments\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The platform operator's payout console: create batches, run the scheduled draw, and
 * drive the maker-checker approval flow. Reads need view_finance or manage_payouts;
 * every mutation needs manage_payouts. The full IBAN is shown only to the transfer
 * executor (manage_payouts); view_finance sees it masked.
 */
class AdminPayoutController extends PlatformBaseController
{
    private const READ = [PlatformPermissionEnum::ViewFinance, PlatformPermissionEnum::ManagePayouts];

    public function __construct(private readonly PayoutService $payouts)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $this->requireAnyPlatformPermission(self::READ);

        $settlements = PayoutSettlement::query()
            ->withCount(['approvals as approve_count' => fn ($q) => $q->where('decision', 'approve')])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('org_id'), fn ($q) => $q->where('organization_id', $request->input('org_id')))
            ->latest('id')
            ->limit(300)
            ->get();

        return successResponse($settlements);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);

        $data = $request->validate([
            'organization_id' => ['required', 'integer'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $organization = Organization::query()->findOrFail($data['organization_id']);
        $settlement = $this->payouts->createBatch(
            $organization,
            $this->admin()->getKey(),
            isset($data['fee']) ? (float) $data['fee'] : null,
            note: $data['note'] ?? null,
        );

        return successResponse($settlement, __('api.created_success'), 201);
    }

    public function balances(): JsonResponse
    {
        $this->requireAnyPlatformPermission(self::READ);

        return successResponse($this->payouts->balances());
    }

    public function runDue(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);

        $weekday = strtolower(Carbon::now()->format('D'));

        return successResponse($this->payouts->runDue($weekday));
    }

    public function settings(): JsonResponse
    {
        $this->requireAnyPlatformPermission(self::READ);

        return successResponse($this->payouts->settings());
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);

        $data = $request->validate([
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'required_approvals' => ['nullable', 'integer', 'min:1', 'max:5'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['in:sun,mon,tue,wed,thu,fri,sat'],
        ]);

        return successResponse($this->payouts->saveSettings($data), __('api.updated_success'));
    }

    public function show(PayoutSettlement $settlement): JsonResponse
    {
        $this->requireAnyPlatformPermission(self::READ);

        $settlement->load(['payments:id,order_id,amount,settlement_id,created_at', 'approvals']);
        $data = $settlement->toArray();
        $data['bank_snapshot'] = $this->presentBank($settlement);

        return successResponse($data);
    }

    public function approve(Request $request, PayoutSettlement $settlement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return successResponse($this->payouts->approve($settlement, $this->admin(), $data['note'] ?? null), __('api.updated_success'));
    }

    public function reject(Request $request, PayoutSettlement $settlement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return successResponse($this->payouts->reject($settlement, $this->admin(), $data['reason'] ?? null), __('api.updated_success'));
    }

    public function fee(Request $request, PayoutSettlement $settlement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);
        $data = $request->validate(['fee' => ['required', 'numeric', 'min:0']]);

        return successResponse($this->payouts->updateFee($settlement, (float) $data['fee']), __('api.updated_success'));
    }

    public function sent(Request $request, PayoutSettlement $settlement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);
        $data = $request->validate(['transfer_ref' => ['required', 'string', 'max:120']]);

        return successResponse($this->payouts->markSent($settlement, $this->admin(), $data['transfer_ref']), __('api.updated_success'));
    }

    public function cancel(Request $request, PayoutSettlement $settlement): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePayouts);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return successResponse($this->payouts->cancel($settlement, $data['reason'] ?? null), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Full IBAN only for the transfer executor; masked for a finance viewer.
     *
     * @return array<string, mixed>|null
     */
    private function presentBank(PayoutSettlement $settlement): ?array
    {
        $bank = $settlement->bank_snapshot;

        if (! is_array($bank)) {
            return null;
        }

        if (! $this->platform->has($this->admin(), PlatformPermissionEnum::ManagePayouts)) {
            $iban = (string) ($bank['iban'] ?? '');
            $bank['iban'] = $iban === '' ? '' : '****'.substr($iban, -4);
        }

        return $bank;
    }
}
