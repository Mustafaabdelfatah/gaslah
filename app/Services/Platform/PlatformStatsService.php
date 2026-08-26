<?php

namespace App\Services\Platform;

use App\Enum\Payments\OnlineChargePurposeEnum;
use App\Enum\Payments\OnlineChargeStatusEnum;
use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformEventTypeEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OnlineCharge;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\PlatformSubscription;
use Illuminate\Support\Carbon;

/**
 * Cross-tenant business metrics for the platform operator: headline KPIs, recurring
 * revenue (MRR/ARR), the monthly MRR-movement waterfall, trial conversion, and the
 * last-twelve-months signup/revenue series.
 *
 * All month bucketing is done in PHP against the platform timezone rather than in SQL,
 * so the numbers are identical on MySQL and SQLite.
 */
class PlatformStatsService
{
    private const MONTHS = 12;

    public function build(): array
    {
        $liveSubscriptions = PlatformSubscription::query()
            ->where('status', PlatformSubscriptionStatusEnum::Active->value)
            ->where(function ($q) {
                $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', Carbon::now());
            })
            ->get(['cycle', 'price', 'plan_id']);

        $mrr = round($liveSubscriptions->sum(fn (PlatformSubscription $s) => $this->monthlyEquivalent($s)), 2);

        return [
            'kpis' => [
                'tenants' => Organization::query()->tenantsOnly()->count(),
                'active_subscriptions' => $liveSubscriptions->count(),
                'customers' => Customer::query()->count(),
                'orders' => Order::query()->count(),
                'branches' => Branch::query()->count(),
                'mrr' => $mrr,
                'arr' => round($mrr * 12, 2),
            ],
            'revenue' => $this->subscriptionRevenue(),
            'trials' => $this->trialMetrics(),
            'mrr_waterfall' => $this->mrrWaterfall(),
            'signups_by_month' => $this->signupsByMonth(),
            'revenue_by_month' => $this->revenueByMonth(),
            'plan_distribution' => $this->planDistribution(),
            'recent_events' => PlatformEvent::query()->latest('created_at')->limit(14)->get(),
        ];
    }

    private function monthlyEquivalent(PlatformSubscription $subscription): float
    {
        $price = (float) $subscription->price;

        return $subscription->cycle === PlatformCycleEnum::Yearly
            ? round($price / 12, 2)
            : $price;
    }

    /**
     * @return array{today: float, month: float, all: float}
     */
    private function subscriptionRevenue(): array
    {
        $now = Carbon::now();
        $paid = OnlineCharge::query()
            ->where('purpose', OnlineChargePurposeEnum::Subscription->value)
            ->where('status', OnlineChargeStatusEnum::Paid->value);

        return [
            'today' => round((clone $paid)->whereBetween('created_at', [$now->copy()->startOfDay(), $now->copy()->endOfDay()])->sum('amount'), 2),
            'month' => round((clone $paid)->whereBetween('created_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()])->sum('amount'), 2),
            'all' => round((clone $paid)->sum('amount'), 2),
        ];
    }

    /**
     * @return array{active: int, started: int, converted: int, conversion_rate: float}
     */
    private function trialMetrics(): array
    {
        $started = PlatformEvent::query()->where('type', PlatformEventTypeEnum::TrialStart->value)->count();
        $converted = PlatformEvent::query()->where('type', PlatformEventTypeEnum::TrialConvert->value)->count();

        return [
            'active' => PlatformSubscription::query()
                ->where('status', PlatformSubscriptionStatusEnum::Trial->value)
                ->where(function ($q) {
                    $q->whereNull('current_period_end')->orWhere('current_period_end', '>=', Carbon::now());
                })
                ->count(),
            'started' => $started,
            'converted' => $converted,
            'conversion_rate' => $started > 0 ? round($converted / $started * 100, 2) : 0.0,
        ];
    }

    /**
     * Monthly MRR movement classified from the event log: new (signups + trial
     * conversions) against churn (expirations).
     *
     * @return array<int, array{month: string, new: float, churn: float, net: float}>
     */
    private function mrrWaterfall(): array
    {
        $buckets = $this->emptyMonthlySeries(fn () => ['new' => 0.0, 'churn' => 0.0]);
        $newTypes = [PlatformEventTypeEnum::Signup->value, PlatformEventTypeEnum::TrialConvert->value, PlatformEventTypeEnum::Renew->value];

        $events = PlatformEvent::query()
            ->where('created_at', '>=', $this->windowStart())
            ->get(['type', 'monthly', 'created_at']);

        foreach ($events as $event) {
            $key = $this->monthKey($event->created_at);
            if (! isset($buckets[$key])) {
                continue;
            }

            if (in_array($event->type->value, $newTypes, true)) {
                $buckets[$key]['new'] += (float) $event->monthly;
            } elseif ($event->type === PlatformEventTypeEnum::Expire) {
                $buckets[$key]['churn'] += (float) $event->monthly;
            }
        }

        return $this->seriesToList($buckets, fn (string $month, array $v) => [
            'month' => $month,
            'new' => round($v['new'], 2),
            'churn' => round($v['churn'], 2),
            'net' => round($v['new'] - $v['churn'], 2),
        ]);
    }

    /**
     * @return array<int, array{month: string, count: int}>
     */
    private function signupsByMonth(): array
    {
        $buckets = $this->emptyMonthlySeries(fn () => 0);

        Organization::query()
            ->tenantsOnly()
            ->where('created_at', '>=', $this->windowStart())
            ->pluck('created_at')
            ->each(function (Carbon $createdAt) use (&$buckets) {
                $key = $this->monthKey($createdAt);
                if (isset($buckets[$key])) {
                    $buckets[$key]++;
                }
            });

        return $this->seriesToList($buckets, fn (string $month, int $count) => ['month' => $month, 'count' => $count]);
    }

    /**
     * @return array<int, array{month: string, revenue: float}>
     */
    private function revenueByMonth(): array
    {
        $buckets = $this->emptyMonthlySeries(fn () => 0.0);

        OnlineCharge::query()
            ->where('purpose', OnlineChargePurposeEnum::Subscription->value)
            ->where('status', OnlineChargeStatusEnum::Paid->value)
            ->where('created_at', '>=', $this->windowStart())
            ->get(['amount', 'created_at'])
            ->each(function (OnlineCharge $charge) use (&$buckets) {
                $key = $this->monthKey($charge->created_at);
                if (isset($buckets[$key])) {
                    $buckets[$key] += (float) $charge->amount;
                }
            });

        return $this->seriesToList($buckets, fn (string $month, float $revenue) => ['month' => $month, 'revenue' => round($revenue, 2)]);
    }

    /**
     * @return array<int, array{plan: string, count: int}>
     */
    private function planDistribution(): array
    {
        return PlatformSubscription::query()
            ->with('plan:id,name')
            ->where('status', PlatformSubscriptionStatusEnum::Active->value)
            ->get(['plan_id'])
            ->groupBy(fn (PlatformSubscription $s) => $s->plan?->name ?? '—')
            ->map(fn ($group, $plan) => ['plan' => $plan, 'count' => $group->count()])
            ->values()
            ->all();
    }

    /*
    |--------------------------------------------------------------------------
    | Month-bucketing helpers (platform timezone, PHP-side)
    |--------------------------------------------------------------------------
    */

    private function windowStart(): Carbon
    {
        return Carbon::now()->startOfMonth()->subMonths(self::MONTHS - 1);
    }

    private function monthKey(Carbon $date): string
    {
        return $date->copy()->timezone(config('app.timezone', 'UTC'))->format('Y-m');
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyMonthlySeries(callable $initial): array
    {
        $buckets = [];
        $cursor = $this->windowStart();

        for ($i = 0; $i < self::MONTHS; $i++) {
            $buckets[$cursor->format('Y-m')] = $initial();
            $cursor = $cursor->copy()->addMonth();
        }

        return $buckets;
    }

    /**
     * @param  array<string, mixed>  $buckets
     * @return array<int, mixed>
     */
    private function seriesToList(array $buckets, callable $map): array
    {
        $list = [];

        foreach ($buckets as $month => $value) {
            $list[] = $map($month, $value);
        }

        return $list;
    }
}
