<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\SubscriptionInvoice;
use App\Services\Platform\SubscriptionInvoicer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin console's view of the platform's subscription invoices, and the two-step
 * draft → confirm billing flow. Reads need view_finance; drafting needs
 * manage_subscriptions; confirming (revenue recognition) needs manage_accounting.
 */
class AdminInvoiceController extends PlatformBaseController
{
    public function __construct(private readonly SubscriptionInvoicer $invoicer)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ViewFinance);

        $invoices = SubscriptionInvoice::query()
            ->with('organization:id,name')
            ->when($request->filled('organization_id'), fn ($q) => $q->where('organization_id', $request->integer('organization_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->latest('id')
            ->paginate(30);

        // Totals recognise issued invoices only — drafts are not revenue.
        $issued = SubscriptionInvoice::query()->issued();

        return successResponse([
            'invoices' => $invoices,
            'totals' => [
                'issued_count' => (clone $issued)->count(),
                'revenue' => round((float) (clone $issued)->sum('subtotal'), 2),
                'vat' => round((float) (clone $issued)->sum('vat'), 2),
                'total' => round((float) (clone $issued)->sum('total'), 2),
            ],
        ]);
    }

    public function show(SubscriptionInvoice $invoice): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ViewFinance);

        return successResponse($invoice->load(['organization:id,name', 'confirmedBy:id,name']));
    }

    public function store(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate([
            'plan_id' => ['nullable', 'integer', 'exists:platform_plans,id'],
            'cycle' => ['nullable', 'in:'.implode(',', PlatformCycleEnum::values())],
            'amount' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:'.implode(',', InvoicePaymentMethodEnum::values())],
            'bank_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,bank_transfer'],
            'transfer_ref' => ['nullable', 'string', 'max:255', 'required_if:payment_method,bank_transfer'],
            'gateway_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,gateway'],
        ]);

        $subscription = $organization->platformSubscription()->with('plan')->first();

        $plan = isset($data['plan_id'])
            ? PlatformPlan::query()->findOrFail($data['plan_id'])
            : $subscription?->plan;

        abort_if($plan === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invoice_plan_required'));

        $cycle = isset($data['cycle'])
            ? PlatformCycleEnum::from($data['cycle'])
            : ($subscription?->cycle ?? PlatformCycleEnum::Monthly);

        // Total: manual amount, else the plan price for the cycle, else the subscription's
        // current price.
        $manualTotal = $data['amount'] ?? ($subscription !== null && ! isset($data['plan_id']) ? (float) $subscription->price : null);

        $invoice = $this->invoicer->quote(
            $organization,
            $plan,
            $cycle,
            InvoicePaymentMethodEnum::from($data['payment_method']),
            [
                'bank_name' => $data['bank_name'] ?? null,
                'transfer_ref' => $data['transfer_ref'] ?? null,
                'gateway_name' => $data['gateway_name'] ?? null,
            ],
            $manualTotal !== null ? (float) $manualTotal : null,
            $subscription,
        );

        return successResponse($invoice, __('api.created_success'), Response::HTTP_CREATED);
    }

    public function confirm(SubscriptionInvoice $invoice): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageAccounting);

        return successResponse(
            $this->invoicer->confirm($invoice, $this->admin()->getKey()),
            __('api.updated_success'),
        );
    }

    public function destroy(SubscriptionInvoice $invoice): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        abort_unless(
            $invoice->status === SubscriptionInvoiceStatusEnum::Draft,
            Response::HTTP_CONFLICT,
            __('api.invoice_issued_no_delete'),
        );

        $invoice->delete();

        return successResponse(null, __('api.deleted_success'));
    }
}
