<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\StoreAccountRequest;
use App\Http\Requests\Accounting\UpdateAccountRequest;
use App\Http\Resources\Accounting\AccountResource;
use App\Models\Account;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Http\JsonResponse;

class AccountController extends TenantController
{
    public function __construct(private readonly ChartOfAccountsService $chart)
    {
        parent::__construct();
    }

    /**
     * The organization's chart of accounts, seeding the system accounts on first view.
     */
    public function index(): JsonResponse
    {
        $this->requireManager();
        $this->chart->ensureChartOfAccounts($this->organizationId());

        // The chart is a fixed, small structure the whole UI needs at once — paginating
        // it would only make the account pickers assemble it again client-side.
        $accounts = Account::query()
            ->forOrganization($this->organizationId())
            ->orderBy('code')
            ->get();

        return successResponse(AccountResource::collection($accounts));
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validated();
        $this->assertCodeIsFree($data['code']);
        $this->assertParentOwned($data['parent_id'] ?? null);

        $account = Account::query()->create([
            ...$data,
            'organization_id' => $this->organizationId(),
            'is_system' => false,
        ]);

        return successResponse($account, __('api.created_success'), 201);
    }

    /**
     * A system account's structure is frozen; only its display names may change.
     */
    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($account);

        $account->update($request->validated());

        return successResponse(new AccountResource($account->refresh()), __('api.updated_success'));
    }

    private function assertCodeIsFree(string $code): void
    {
        $exists = Account::query()
            ->forOrganization($this->organizationId())
            ->where('code', $code)
            ->exists();

        abort_if($exists, 422, __('validation.unique', ['attribute' => 'code']));
    }

    private function assertParentOwned(?int $parentId): void
    {
        if ($parentId === null) {
            return;
        }

        $owned = Account::query()->forOrganization($this->organizationId())->whereKey($parentId)->exists();
        abort_unless($owned, 422, __('api.account_not_owned'));
    }
}
