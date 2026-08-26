<?php

namespace App\Services\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Models\DunningLog;
use App\Models\Organization;
use App\Models\PlatformSubscription;
use App\Services\Messaging\Providers\MessagingProvider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The subscription dunning cycle: remind before renewal, raise a renewal draft and drop
 * to PAST_DUE at the due date, keep reminding while overdue, then suspend after the grace
 * period. One idempotent daily run — every (organization, stage, period) fires at most
 * once, guarded by the unique key on {@see DunningLog}.
 *
 * There is no automatic charge (the gateway is not tokenised); the tenant pays against the
 * invoice. Reminder delivery is best-effort — a channel failure never aborts the cycle.
 */
class DunningService
{
    /**
     * @var array{enabled: bool, remind_days_before: array<int,int>, remind_days_after: array<int,int>, grace_days: int, channels: array{whatsapp: bool, email: bool}}
     */
    private const DEFAULTS = [
        'enabled' => false,
        'remind_days_before' => [3],
        'remind_days_after' => [3, 7],
        'grace_days' => 14,
        'channels' => ['whatsapp' => true, 'email' => true],
    ];

    private const CONFIG_KEY = 'platform.dunning';

    public function __construct(
        private readonly PlatformConfigStore $config,
        private readonly SubscriptionInvoicer $invoicer,
        private readonly MessagingProvider $messenger,
    ) {}

    /**
     * The effective policy: stored overrides merged over the defaults.
     *
     * @return array<string, mixed>
     */
    public function policy(): array
    {
        $stored = $this->config->get(self::CONFIG_KEY, []);
        $stored = is_array($stored) ? $stored : [];

        return [
            ...self::DEFAULTS,
            ...$stored,
            'channels' => [...self::DEFAULTS['channels'], ...(is_array($stored['channels'] ?? null) ? $stored['channels'] : [])],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function savePolicy(array $input): array
    {
        $policy = [...$this->policy(), ...$input];
        $this->config->put(self::CONFIG_KEY, $policy);

        return $policy;
    }

    /**
     * Run one dunning cycle. Idempotent: safe to run repeatedly in a day.
     *
     * @return array{processed: int, reminders: int, invoices: int, lapsed: int, suspended: int}
     */
    public function run(): array
    {
        $summary = ['processed' => 0, 'reminders' => 0, 'invoices' => 0, 'lapsed' => 0, 'suspended' => 0];

        $policy = $this->policy();

        if (! $policy['enabled']) {
            return $summary;
        }

        $booksOrgId = Organization::reservedBooksOrgId();
        $now = Carbon::now();
        $today = $now->copy()->startOfDay();

        $subscriptions = PlatformSubscription::query()
            ->with(['organization', 'plan'])
            ->whereNotNull('current_period_end')
            ->whereIn('status', [
                PlatformSubscriptionStatusEnum::Active->value,
                PlatformSubscriptionStatusEnum::Trial->value,
                PlatformSubscriptionStatusEnum::PastDue->value,
            ])
            ->when($booksOrgId !== null, fn ($q) => $q->where('organization_id', '!=', $booksOrgId))
            ->get();

        foreach ($subscriptions as $subscription) {
            $organization = $subscription->organization;

            if ($organization === null || $organization->is_suspended) {
                continue;
            }

            $summary['processed']++;

            $periodKey = $subscription->current_period_end->format('Y-m-d');
            $daysUntil = $today->diffInDays($subscription->current_period_end->copy()->startOfDay(), false);

            $this->handleSubscription($subscription, $organization, $policy, $periodKey, (int) $daysUntil, $summary);
        }

        return $summary;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $policy
     * @param  array{processed: int, reminders: int, invoices: int, lapsed: int, suspended: int}  $summary
     */
    private function handleSubscription(
        PlatformSubscription $subscription,
        Organization $organization,
        array $policy,
        string $periodKey,
        int $daysUntil,
        array &$summary,
    ): void {
        $orgId = $organization->getKey();
        $isRenewable = in_array($subscription->status, [PlatformSubscriptionStatusEnum::Active, PlatformSubscriptionStatusEnum::Trial], true);

        // 1. Pre-renewal reminder.
        if ($isRenewable && $daysUntil > 0 && in_array($daysUntil, $policy['remind_days_before'], true)) {
            $message = $subscription->status === PlatformSubscriptionStatusEnum::Trial ? 'trial_ending' : 'dunning';
            if ($this->mark($orgId, "{$periodKey}:before-{$daysUntil}", 'remind_before', $message)) {
                $this->deliver($organization, $message, $policy);
                $summary['reminders']++;
            }
        }

        // 2. Due reached: raise a renewal draft once, drop to PAST_DUE, remind.
        if ($isRenewable && $daysUntil <= 0) {
            if ($this->mark($orgId, "{$periodKey}:invoice", 'invoice', 'dunning')) {
                $this->quoteRenewal($subscription, $organization);
                $summary['invoices']++;
                $this->deliver($organization, 'dunning', $policy);
                $summary['reminders']++;
            }

            $subscription->forceFill(['status' => PlatformSubscriptionStatusEnum::PastDue->value])->save();
            $summary['lapsed']++;
        }

        // 3. Overdue handling (PAST_DUE, including the row just transitioned above).
        if ($subscription->status === PlatformSubscriptionStatusEnum::PastDue) {
            $overdue = $daysUntil < 0 ? abs($daysUntil) : 0;

            if ($overdue > 0 && in_array($overdue, $policy['remind_days_after'], true)) {
                if ($this->mark($orgId, "{$periodKey}:after-{$overdue}", 'remind_after', 'dunning')) {
                    $this->deliver($organization, 'dunning', $policy);
                    $summary['reminders']++;
                }
            }

            if ($overdue >= $policy['grace_days']) {
                if ($this->mark($orgId, "{$periodKey}:suspend", 'suspend', 'suspended')) {
                    $organization->forceFill(['is_suspended' => true])->save();
                    $this->deliver($organization, 'suspended', $policy);
                    $summary['suspended']++;
                    $summary['reminders']++;
                }
            }
        }
    }

    private function quoteRenewal(PlatformSubscription $subscription, Organization $organization): void
    {
        if ($subscription->plan === null) {
            return;
        }

        $this->invoicer->quote(
            $organization,
            $subscription->plan,
            $subscription->cycle,
            InvoicePaymentMethodEnum::BankTransfer,
            [],
            null,
            $subscription,
        );
    }

    /**
     * Record a dunning stage, returning false if it already fired for this period. The
     * unique (organization, key) index is the concurrency-safe guard.
     */
    private function mark(int $orgId, string $key, string $stage, ?string $message): bool
    {
        if (DunningLog::query()->where('organization_id', $orgId)->where('key', $key)->exists()) {
            return false;
        }

        try {
            DunningLog::query()->create([
                'organization_id' => $orgId,
                'key' => $key,
                'stage' => $stage,
                'message' => $message,
                'created_at' => Carbon::now(),
            ]);
        } catch (QueryException $exception) {
            // A concurrent run marked the same stage first — that is the desired outcome.
            if (! in_array($exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }

            return false;
        }

        return true;
    }

    /**
     * Best-effort reminder delivery to the organization's contact number. A channel
     * failure is swallowed so one org cannot abort the whole cycle.
     *
     * @param  array<string, mixed>  $policy
     */
    private function deliver(Organization $organization, string $message, array $policy): void
    {
        $whatsapp = (bool) ($policy['channels']['whatsapp'] ?? false);

        if (! $whatsapp || empty($organization->phone) || ! $this->messenger->canDeliver()) {
            return;
        }

        try {
            $this->messenger->send((string) $organization->phone, __("api.dunning_{$message}", ['org' => $organization->name]), 'whatsapp');
        } catch (Throwable) {
            // Delivery is best-effort; the durable record is the dunning log row.
        }
    }
}
