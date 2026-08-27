<?php

namespace App\Services\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Http\Requests\Platform\DraftInvoiceRequest;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\SubscriptionInvoice;
use App\Support\Zatca;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step ZATCA billing for tenant subscriptions, with the platform as seller.
 *
 * A price is treated as VAT-inclusive (tax extracted at 15/115). {@see quote} writes a
 * freely-deletable draft that holds no chain slot and posts nothing. {@see confirm}, once
 * the operator has received payment, assigns the next SUB- ICV/PIH slot, freezes the row,
 * and posts revenue to the platform books — all in one transaction, so an issued invoice
 * and its journal entry never exist without each other.
 */
class SubscriptionInvoicer
{
    public function __construct(
        private readonly PlatformBooks $books,
        private readonly PlatformInvoiceChain $chain,
    ) {}

    /**
     * Draft an invoice for a tenant from a console request, filling the gaps from the
     * tenant's own subscription.
     *
     * Plan: the one asked for, else the subscribed plan. Cycle: likewise. Total: the
     * manual amount, else — when no other plan was named — the price the tenant is
     * actually paying, else the plan's list price for the cycle.
     */
    public function quoteForTenant(Organization $organization, DraftInvoiceRequest $request): SubscriptionInvoice
    {
        $subscription = $organization->platformSubscription()->with('plan')->first();

        $plan = $request->plan() ?? $subscription?->plan;
        abort_if($plan === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invoice_plan_required'));

        $cycle = $request->cycle() ?? $subscription?->cycle ?? PlatformCycleEnum::Monthly;

        $total = $request->amount();
        if ($total === null && $subscription !== null && $request->plan() === null) {
            $total = (float) $subscription->price;
        }

        return $this->quote(
            $organization,
            $plan,
            $cycle,
            $request->paymentMethod(),
            $request->paymentMeta(),
            $total,
            $subscription,
        );
    }

    /**
     * Create a DRAFT invoice for a subscription period. No chain slot, no ledger entry.
     *
     * @param  array{bank_name?: string|null, transfer_ref?: string|null, gateway_name?: string|null}  $paymentMeta
     */
    public function quote(
        Organization $organization,
        PlatformPlan $plan,
        PlatformCycleEnum $cycle,
        InvoicePaymentMethodEnum $method,
        array $paymentMeta = [],
        ?float $manualTotal = null,
        ?PlatformSubscription $subscription = null,
        ?int $chargeId = null,
    ): SubscriptionInvoice {
        $money = $this->chain->splitInclusive($manualTotal ?? $this->planPrice($plan, $cycle));
        $seller = $this->chain->seller();

        return SubscriptionInvoice::query()->create([
            'organization_id' => $organization->getKey(),
            'subscription_id' => $subscription?->getKey(),
            'charge_id' => $chargeId,
            'seller_name' => $seller['name'],
            'seller_vat' => $seller['vat'],
            'buyer_name' => $organization->name,
            'buyer_vat' => $organization->vat_number,
            'plan_name' => $plan->name,
            'cycle' => $cycle->value,
            'subtotal' => $money['subtotal'],
            'vat' => $money['vat'],
            'total' => $money['total'],
            'payment_method' => $method->value,
            'bank_name' => $paymentMeta['bank_name'] ?? null,
            'transfer_ref' => $paymentMeta['transfer_ref'] ?? null,
            'gateway_name' => $paymentMeta['gateway_name'] ?? null,
            'status' => SubscriptionInvoiceStatusEnum::Draft->value,
            'created_at' => Carbon::now(),
        ]);
    }

    /**
     * Confirm a draft: assign the next chain slot, freeze it as ISSUED, and post revenue —
     * atomically. A concurrent second confirm affects zero rows and is refused with 409.
     */
    public function confirm(SubscriptionInvoice $invoice, ?int $adminId = null): SubscriptionInvoice
    {
        abort_unless($invoice->isDraft(), Response::HTTP_CONFLICT, __('api.invoice_already_issued'));

        /** @var SubscriptionInvoice $issued */
        $issued = $this->chain->issue(
            $invoice,
            SubscriptionInvoice::query()->issued(),
            fn (SubscriptionInvoice $draft, int $icv, string $pih, string $timestamp) => $this->canonical($draft, $icv, $pih, $timestamp),
            fn (SubscriptionInvoice $confirmed) => $this->books->postRevenue($confirmed),
            $adminId,
        );

        return $issued;
    }

    /**
     * Quote + confirm in one call, for historical backfill only (no draft stage). Wrapped
     * so a confirm failure rolls the orphan draft back too.
     */
    public function issue(
        Organization $organization,
        PlatformPlan $plan,
        PlatformCycleEnum $cycle,
        InvoicePaymentMethodEnum $method,
        array $paymentMeta = [],
        ?float $manualTotal = null,
        ?PlatformSubscription $subscription = null,
        ?int $chargeId = null,
        ?int $adminId = null,
    ): SubscriptionInvoice {
        return DB::transaction(function () use ($organization, $plan, $cycle, $method, $paymentMeta, $manualTotal, $subscription, $chargeId, $adminId) {
            $draft = $this->quote($organization, $plan, $cycle, $method, $paymentMeta, $manualTotal, $subscription, $chargeId);

            return $this->confirm($draft, $adminId);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function planPrice(PlatformPlan $plan, PlatformCycleEnum $cycle): float
    {
        return (float) ($cycle === PlatformCycleEnum::Yearly ? $plan->yearly_price : $plan->monthly_price);
    }

    /**
     * A deterministic canonical string over the invoice's tax-relevant fields — the input
     * to the chained hash.
     */
    private function canonical(SubscriptionInvoice $invoice, int $icv, string $pih, string $timestamp): string
    {
        $seller = $this->chain->seller();

        return implode('|', [
            'SUB',
            $icv,
            $pih,
            $timestamp,
            $seller['name'],
            (string) $seller['vat'],
            (string) $invoice->buyer_name,
            (string) $invoice->buyer_vat,
            (string) $invoice->plan_name,
            $invoice->cycle instanceof PlatformCycleEnum ? $invoice->cycle->value : (string) $invoice->cycle,
            Zatca::money((float) $invoice->subtotal),
            Zatca::money((float) $invoice->vat),
            Zatca::money((float) $invoice->total),
        ]);
    }
}
