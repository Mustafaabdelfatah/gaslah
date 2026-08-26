<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartOfAccountsTest extends TestCase
{
    use RefreshDatabase;

    private ChartOfAccountsService $chart;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->chart = app(ChartOfAccountsService::class);
        $this->organization = $this->createOrganization();
    }

    public function test_seeding_creates_the_full_core_chart(): void
    {
        $this->chart->ensureChartOfAccounts($this->organization->getKey());

        $accounts = Account::query()->forOrganization($this->organization->getKey())->get();

        $this->assertCount(16, $accounts);
        $this->assertTrue($accounts->every(fn (Account $account) => $account->is_system));
        $this->assertNotNull($accounts->firstWhere('system_key', SystemAccountEnum::DeferredRevenue->value));
    }

    public function test_seeding_twice_does_not_duplicate_accounts(): void
    {
        $this->chart->ensureChartOfAccounts($this->organization->getKey());
        $this->chart->ensureChartOfAccounts($this->organization->getKey());

        // Idempotent: the second pass skips everything that already exists.
        $this->assertSame(16, Account::query()->forOrganization($this->organization->getKey())->count());
    }

    public function test_system_accounts_are_isolated_per_organization(): void
    {
        $other = $this->createOrganization();

        $this->chart->ensureChartOfAccounts($this->organization->getKey());
        $this->chart->ensureChartOfAccounts($other->getKey());

        $mine = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::Cash);
        $theirs = $this->chart->systemAccount($other->getKey(), SystemAccountEnum::Cash);

        // Same system key, different rows — each organization owns its own cash account.
        $this->assertNotSame($mine->getKey(), $theirs->getKey());
        $this->assertSame($this->organization->getKey(), $mine->organization_id);
    }

    public function test_resolving_a_system_account_seeds_the_chart_on_first_use(): void
    {
        // No explicit ensure call: resolving a core account seeds the chart lazily.
        $cash = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::Cash);

        $this->assertSame('1010', $cash->code);
    }

    public function test_fixed_asset_accounts_are_seeded_lazily_on_first_request(): void
    {
        $this->chart->ensureChartOfAccounts($this->organization->getKey());

        // The core chart has 16 accounts and no depreciation account yet.
        $this->assertNull(
            Account::query()->forOrganization($this->organization->getKey())
                ->systemKey(SystemAccountEnum::AccumulatedDepreciation)->first()
        );

        $accumulated = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::AccumulatedDepreciation);

        $this->assertSame('1590', $accumulated->code);
        $this->assertSame(21, Account::query()->forOrganization($this->organization->getKey())->count());
    }

    public function test_a_system_account_reports_itself_as_structurally_locked(): void
    {
        $cash = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::Cash);

        $this->assertTrue($cash->isStructurallyLocked());
    }
}
