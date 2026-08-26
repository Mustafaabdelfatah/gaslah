<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves which organization and branches the current request may touch.
 *
 * Two different questions are answered here and must not be confused. The *write*
 * branch is where new records land — it is pinned at sign-in and stays put. The
 * *read* branches are what a listing may show, and a caller can narrow those with a
 * header without moving where their next order or shift is recorded.
 */
class TenantContext
{
    /**
     * Header a client sends to narrow listings to a single branch.
     */
    public const BRANCH_HEADER = 'X-Branch-Id';

    private bool $resolved = false;

    private ?User $user = null;

    private ?int $organizationId = null;

    private ?int $writeBranchId = null;

    /**
     * @var array<int, int>|null
     */
    private ?array $organizationBranchIds = null;

    public function __construct(private readonly Request $request) {}

    /**
     * Bind an explicit user, bypassing the authenticated one.
     */
    public function forUser(?User $user): static
    {
        $this->reset();
        $this->user = $user;
        $this->resolved = true;
        $this->resolveOrganization();

        return $this;
    }

    public function user(): ?User
    {
        $this->boot();

        return $this->user;
    }

    public function organizationId(): ?int
    {
        $this->boot();

        return $this->organizationId;
    }

    public function organization(): ?Organization
    {
        $id = $this->organizationId();

        return $id === null ? null : Organization::find($id);
    }

    public function hasOrganization(): bool
    {
        return $this->organizationId() !== null;
    }

    /**
     * The organization of the current caller, refusing the request when there is none.
     *
     * A staff account detached from every branch cannot act on tenant data, and a
     * platform administrator has no organization of their own to act within.
     */
    public function requireOrganizationId(): int
    {
        $organizationId = $this->organizationId();

        if ($organizationId === null) {
            abort(403, __('api.account_not_linked_to_organization'));
        }

        return $organizationId;
    }

    /**
     * The branch new records are attributed to.
     *
     * Pinned at sign-in and kept for as long as the membership survives. Falling back
     * to "the first branch" on every request would let writes drift between branches
     * for anyone who covers two of them, landing orders and shifts against a till
     * that is not open.
     */
    public function writeBranchId(): ?int
    {
        $this->boot();

        return $this->writeBranchId;
    }

    public function requireWriteBranchId(): int
    {
        $branchId = $this->writeBranchId();

        if ($branchId === null) {
            abort(403, __('api.account_not_linked_to_branch'));
        }

        return $branchId;
    }

    /**
     * Branches a listing may include.
     *
     * Defaults to every branch of the caller's organization. The header only ever
     * narrows this set: naming a branch outside the organization is ignored rather
     * than honoured, so the header cannot be used to reach another tenant.
     *
     * @return array<int, int>
     */
    public function readBranchIds(): array
    {
        $branchIds = $this->organizationBranchIds();

        $requested = $this->requestedBranchId();

        if ($requested !== null && in_array($requested, $branchIds, true)) {
            return [$requested];
        }

        return $branchIds;
    }

    /**
     * @return array<int, int>
     */
    public function organizationBranchIds(): array
    {
        $this->boot();

        if ($this->organizationBranchIds !== null) {
            return $this->organizationBranchIds;
        }

        if ($this->organizationId === null) {
            return $this->organizationBranchIds = [];
        }

        return $this->organizationBranchIds = Branch::query()
            ->where('organization_id', $this->organizationId)
            ->pluck('id')
            ->all();
    }

    public function requestedBranchId(): ?int
    {
        $value = $this->request->header(self::BRANCH_HEADER);

        return is_numeric($value) ? (int) $value : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function boot(): void
    {
        if ($this->resolved) {
            return;
        }

        $this->resolved = true;

        $user = auth()->user();
        $this->user = $user instanceof User ? $user : null;

        $this->resolveOrganization();
    }

    private function resolveOrganization(): void
    {
        if (! $this->user instanceof User) {
            return;
        }

        $membership = $this->user->userBranches()
            ->with('branch')
            ->get()
            ->filter(fn ($userBranch) => $userBranch->branch !== null);

        if ($membership->isEmpty()) {
            return;
        }

        $pinnedBranchId = $this->pinnedBranchId();

        $active = $membership->firstWhere('branch_id', $pinnedBranchId)
            ?? $membership->first();

        $this->writeBranchId = $active->branch_id;
        $this->organizationId = $active->branch->organization_id;
    }

    /**
     * The branch recorded on the current token at sign-in, if any.
     */
    private function pinnedBranchId(): ?int
    {
        $token = $this->user?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return null;
        }

        $branchId = data_get($token->meta, 'branch_id');

        return is_numeric($branchId) ? (int) $branchId : null;
    }

    private function reset(): void
    {
        $this->resolved = false;
        $this->user = null;
        $this->organizationId = null;
        $this->writeBranchId = null;
        $this->organizationBranchIds = null;
    }
}
