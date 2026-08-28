<?php

namespace App\Services\Platform;

use App\Enum\Platform\PlatformAuditActionEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Archiving a tenant, and handing its own data back.
 *
 * There is deliberately no delete here, hard or otherwise. Every tenant shares one
 * database, and a cascade from an organization row would take orders, invoices and
 * accounting entries with it — including the platform's own books, which reference the
 * tenant. Archiving takes the account out of circulation and leaves the trail intact.
 */
class TenantDataService
{
    /**
     * The most customers one export will carry. Beyond this the bundle says so rather
     * than silently handing back a partial list that reads as complete.
     */
    private const CUSTOMER_CAP = 10000;

    public function __construct(private readonly PlatformAuditService $audit) {}

    /**
     * Take a tenant out of circulation.
     *
     * Archiving suspends too: an archived account that could still be written to would be
     * archived in name only. Both are set together so neither can be left behind.
     */
    public function archive(Organization $organization, User $admin, ?string $reason = null): Organization
    {
        abort_if($organization->isArchived(), Response::HTTP_CONFLICT, __('api.tenant_already_archived'));

        DB::transaction(function () use ($organization) {
            $organization->forceFill([
                'archived_at' => Carbon::now(),
                'is_suspended' => true,
            ])->save();
        });

        $this->audit->log($admin, PlatformAuditActionEnum::Archive, $organization, array_filter(['reason' => $reason]));

        return $organization->refresh();
    }

    /**
     * Bring an archived tenant back.
     *
     * The suspension is lifted with it — an account restored but still suspended would
     * leave the operator wondering which switch they had missed.
     */
    public function unarchive(Organization $organization, User $admin): Organization
    {
        abort_unless($organization->isArchived(), Response::HTTP_CONFLICT, __('api.tenant_not_archived'));

        DB::transaction(function () use ($organization) {
            $organization->forceFill([
                'archived_at' => null,
                'is_suspended' => false,
            ])->save();
        });

        $this->audit->log($admin, PlatformAuditActionEnum::Unarchive, $organization);

        return $organization->refresh();
    }

    /**
     * The tenant's own data, for handing back to them.
     *
     * This is personal data — the customers a laundry serves, by name and phone — so the
     * export is the owner's alone and always leaves an audit entry naming who took it.
     *
     * @return array<string, mixed>
     */
    public function export(Organization $organization, User $admin): array
    {
        $customerCount = Customer::query()->where('organization_id', $organization->getKey())->count();
        $truncated = $customerCount > self::CUSTOMER_CAP;

        $bundle = [
            'exported_at' => Carbon::now()->toIso8601String(),
            'organization' => $this->profile($organization),
            'branches' => $this->branches($organization),
            'staff' => $this->staff($organization),
            'customers' => $this->customers($organization),

            // Stated outright, not inferred from a count: an export that quietly stops at
            // ten thousand reads as the whole book to whoever receives it.
            'customers_total' => $customerCount,
            'customers_exported' => $truncated ? self::CUSTOMER_CAP : $customerCount,
            'customers_truncated' => $truncated,
            'export_note' => $truncated
                ? __('api.export_truncated', ['cap' => self::CUSTOMER_CAP, 'total' => $customerCount])
                : null,
        ];

        $this->audit->log($admin, PlatformAuditActionEnum::Export, $organization, [
            'customers_exported' => $bundle['customers_exported'],
            'customers_truncated' => $truncated,
        ]);

        return $bundle;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function profile(Organization $organization): array
    {
        return $organization->only([
            'id', 'name', 'slug', 'custom_domain', 'default_currency', 'tax_rate',
            'phone', 'email', 'address', 'cr_number', 'vat_number',
            'is_suspended', 'archived_at', 'created_at',
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function branches(Organization $organization): array
    {
        return Branch::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->get(['id', 'name', 'code', 'phone', 'address', 'is_active', 'created_at'])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staff(Organization $organization): array
    {
        $branchIds = Branch::query()->where('organization_id', $organization->getKey())->pluck('id');

        return User::query()
            ->whereHas('branches', fn ($query) => $query->whereIn('branches.id', $branchIds))
            ->orderBy('id')
            ->get(['id', 'name', 'email', 'phone', 'is_active', 'created_at'])
            ->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function customers(Organization $organization): array
    {
        return Customer::query()
            ->where('organization_id', $organization->getKey())
            ->orderBy('id')
            ->limit(self::CUSTOMER_CAP)
            ->get(['id', 'name', 'phone', 'email', 'address', 'type', 'credit_limit', 'created_at'])
            ->toArray();
    }
}
