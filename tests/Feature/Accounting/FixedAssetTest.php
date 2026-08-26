<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\AssetAcquisitionSourceEnum;
use App\Enum\Accounting\AssetCategoryEnum;
use App\Enum\Accounting\AssetStatusEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\AssetDepreciationEntry;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\AssetService;
use App\Services\Accounting\ChartOfAccountsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    private AssetService $assets;

    private AccountingReportService $reports;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assets = app(AssetService::class);
        $this->reports = app(AccountingReportService::class);
        $this->organization = $this->createOrganization();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    public function test_creating_an_asset_paid_from_cash_posts_its_acquisition(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        $this->assertTrue($asset->acquisition_posted);
        $entry = JournalEntry::query()->find($asset->acquisition_journal_entry_id)->load('lines');
        $this->assertEquals('12000.00', $this->line($entry, SystemAccountEnum::FixedAsset)->debit);
        $this->assertEquals('12000.00', $this->line($entry, SystemAccountEnum::Cash)->credit);
    }

    public function test_an_asset_recorded_as_none_posts_no_acquisition(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::None);

        $this->assertFalse($asset->acquisition_posted);
        $this->assertNull($asset->acquisition_journal_entry_id);
    }

    public function test_depreciation_charges_one_entry_per_whole_month_due(): void
    {
        // 12,000 over 12 months = 1,000/month. Three months on: three charges.
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        $this->assets->depreciate($asset, Carbon::parse('2026-04-01'));

        $this->assertSame(3, AssetDepreciationEntry::query()->where('fixed_asset_id', $asset->getKey())->count());
        $this->assertEquals('3000.00', $asset->fresh()->accumulated_depreciation);
    }

    public function test_re_running_depreciation_never_double_charges_a_month(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        $this->assets->depreciate($asset, Carbon::parse('2026-04-01'));
        // A second sweep over the same window must add nothing — the (asset, period)
        // guard makes each month single-charge.
        $this->assets->depreciate($asset->fresh(), Carbon::parse('2026-04-01'));

        $this->assertSame(3, AssetDepreciationEntry::query()->where('fixed_asset_id', $asset->getKey())->count());
        $this->assertEquals('3000.00', $asset->fresh()->accumulated_depreciation);
    }

    public function test_depreciation_stops_at_the_salvage_value(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        // Far beyond the useful life: total depreciation caps at cost minus salvage.
        $this->assets->depreciate($asset, Carbon::parse('2030-01-01'));

        $this->assertEquals('12000.00', $asset->fresh()->accumulated_depreciation);
        $this->assertSame(12, AssetDepreciationEntry::query()->where('fixed_asset_id', $asset->getKey())->count());
    }

    public function test_disposal_catches_up_depreciation_and_records_the_gain(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        // Sold after 6 months (book value 6,000) for 8,000 → a 2,000 gain.
        $disposed = $this->assets->dispose($asset, ['proceeds' => 8000, 'via' => 'bank', 'date' => '2026-07-01']);

        $this->assertSame(AssetStatusEnum::Disposed, $disposed->status);
        $this->assertEquals('2000.00', $disposed->disposal_gain);
        $this->assertTrue($this->reports->trialBalance($this->organization->getKey())['balanced']);
    }

    public function test_a_disposed_asset_cannot_be_disposed_again(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);
        $this->assets->dispose($asset, ['proceeds' => 1000, 'date' => '2026-07-01']);

        $this->assertAborts(422, fn () => $this->assets->dispose($asset->fresh(), ['proceeds' => 500, 'date' => '2026-08-01']));
    }

    public function test_an_asset_with_a_ledger_footprint_cannot_be_deleted(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::Cash);

        $this->assertAborts(422, fn () => $this->assets->delete($asset));
    }

    public function test_a_footprint_free_asset_can_be_deleted(): void
    {
        $asset = $this->createAsset(paidFrom: AssetAcquisitionSourceEnum::None);

        $this->assets->delete($asset);

        $this->assertNull(FixedAsset::query()->find($asset->getKey()));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function createAsset(AssetAcquisitionSourceEnum $paidFrom): FixedAsset
    {
        return $this->assets->create([
            'organization_id' => $this->organization->getKey(),
            'name' => 'Industrial Washer',
            'category' => AssetCategoryEnum::Equipment->value,
            'cost' => 12000,
            'purchase_date' => '2026-01-01',
            'useful_life_months' => 12,
            'salvage_value' => 0,
            'paid_from' => $paidFrom->value,
        ]);
    }

    private function line(JournalEntry $entry, SystemAccountEnum $key): JournalLine
    {
        $accountId = Account::query()->forOrganization($this->organization->getKey())->systemKey($key)->value('id');

        return $entry->lines->firstWhere('account_id', $accountId);
    }
}
