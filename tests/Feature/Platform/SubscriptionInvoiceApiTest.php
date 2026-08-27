<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Services\Platform\PlatformBooks;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SubscriptionInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization] = $this->createTenant();
    }

    public function test_a_draft_invoice_extracts_vat_from_the_inclusive_total(): void
    {
        $plan = PlatformPlan::factory()->create(['name' => 'Pro', 'monthly_price' => 115]);
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'cycle' => 'monthly',
            'payment_method' => 'cash',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.total', '115.00')
            ->assertJsonPath('data.vat', '15.00')
            ->assertJsonPath('data.subtotal', '100.00')
            ->assertJsonPath('data.icv', null);
    }

    public function test_bank_transfer_requires_its_reference_fields(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'payment_method' => 'bank_transfer',
        ])->assertStatus(422)->assertJsonValidationErrors(['bank_name', 'transfer_ref']);
    }

    public function test_confirming_a_draft_issues_it_and_posts_revenue(): void
    {
        $plan = PlatformPlan::factory()->create(['monthly_price' => 115]);
        Sanctum::actingAs($this->owner());

        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', 'issued')
            ->assertJsonPath('data.icv', 1)
            ->assertJsonPath('data.invoice_no', 1);

        $invoice = SubscriptionInvoice::query()->find($invoiceId);
        $this->assertNotNull($invoice->hash);
        $this->assertNotNull($invoice->qr);
        $this->assertNotNull($invoice->issued_at);

        // Revenue posted to the platform books: Dr Bank 115 / Cr Sales 100 / Cr VAT 15.
        $booksOrgId = app(PlatformBooks::class)->storedOrgId();
        $this->assertNotNull($booksOrgId);

        $entry = JournalEntry::query()
            ->where('organization_id', $booksOrgId)
            ->where('ref_type', 'SubscriptionInvoice')
            ->where('ref_id', (string) $invoiceId)
            ->first();
        $this->assertNotNull($entry);

        $debit = (float) JournalLine::query()->where('journal_entry_id', $entry->getKey())->sum('debit');
        $credit = (float) JournalLine::query()->where('journal_entry_id', $entry->getKey())->sum('credit');
        $this->assertSame(115.0, round($debit, 2));
        $this->assertSame(115.0, round($credit, 2));
    }

    public function test_the_platform_books_org_is_hidden_from_the_tenant_directory(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'payment_method' => 'cash',
        ])->json('data.id');
        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")->assertOk();

        $slugs = collect($this->getJson('/api/admin/tenants')->json('data.data'))->pluck('slug');
        $this->assertFalse($slugs->contains('platform-books'));
    }

    public function test_a_second_confirm_is_refused_and_issued_invoices_cannot_be_deleted(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")->assertOk();
        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")->assertStatus(409);
        $this->deleteJson("/api/admin/invoices/{$invoiceId}")->assertStatus(409);
    }

    public function test_a_draft_can_be_deleted(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(),
            'payment_method' => 'cash',
        ])->json('data.id');

        $this->deleteJson("/api/admin/invoices/{$invoiceId}")->assertOk();
        $this->assertNull(SubscriptionInvoice::query()->find($invoiceId));
    }

    public function test_the_listing_is_paginated_filterable_and_totals_only_issued(): void
    {
        $plan = PlatformPlan::factory()->create(['monthly_price' => 115]);
        Sanctum::actingAs($this->owner());

        $draft = fn () => $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(), 'payment_method' => 'cash',
        ])->json('data.id');

        $confirmed = $draft();
        $draft();
        $this->postJson("/api/admin/invoices/{$confirmed}/confirm")->assertOk();

        // Both invoices are listed, but only the issued one counts as revenue.
        $this->getJson('/api/admin/invoices')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            ->assertJsonPath('data.totals.issued_count', 1)
            ->assertJsonPath('data.totals.revenue', 100)
            ->assertJsonPath('data.totals.vat', 15);

        $this->getJson('/api/admin/invoices?status=draft')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.status', 'draft');
    }

    public function test_confirming_needs_the_accounting_permission(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $invoiceId = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/invoices", [
            'plan_id' => $plan->getKey(), 'payment_method' => 'cash',
        ])->json('data.id');

        // A finance-only admin may read invoices but must not recognise revenue.
        Sanctum::actingAs($this->adminWith(PlatformPermissionEnum::ViewFinance));

        $this->getJson('/api/admin/invoices')->assertOk();
        $this->postJson("/api/admin/invoices/{$invoiceId}/confirm")->assertStatus(403);
    }

    private function adminWith(PlatformPermissionEnum $permission): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();
        $user->platformPermissions()->create(['permission' => $permission->value]);

        return $user;
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}
