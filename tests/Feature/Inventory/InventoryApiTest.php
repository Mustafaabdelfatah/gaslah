<?php

namespace Tests\Feature\Inventory;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\InventoryItem;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InventoryApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Unit $unit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        $this->unit = Unit::factory()->create(['organization_id' => $this->organization->getKey()]);
    }

    public function test_a_manager_creates_an_item_and_low_stock_is_computed(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->postJson('/api/inventory/items', [
            'name' => 'Detergent', 'unit_id' => $this->unit->getKey(), 'quantity' => 5, 'reorder_level' => 10,
        ])->assertCreated()->assertJsonPath('data.low_stock', true);

        // Paginated envelope, with the tenant-wide low-stock count carried as meta.
        $this->getJson('/api/inventory/items')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.low_stock', 1);
    }

    public function test_a_foreign_unit_is_refused(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $foreignUnit = Unit::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->postJson('/api/inventory/items', ['name' => 'X', 'unit_id' => $foreignUnit->getKey()])
            ->assertStatus(422);
    }

    public function test_low_stock_lists_only_items_at_or_below_reorder(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        InventoryItem::factory()->low()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'unit_id' => $this->unit->getKey()]);
        InventoryItem::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'unit_id' => $this->unit->getKey(), 'quantity' => 100, 'reorder_level' => 10]);

        $this->getJson('/api/inventory/low-stock')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_reception_member_cannot_create_an_item(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Reception));

        $this->postJson('/api/inventory/items', ['name' => 'X', 'unit_id' => $this->unit->getKey()])
            ->assertStatus(403);
    }

    public function test_inventory_requires_the_feature(): void
    {
        $this->organization->update(['feature_overrides' => ['inventory' => false]]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/inventory/items')->assertStatus(403);
    }

    public function test_suppliers_crud_and_purchase_orders_are_read_only(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $id = $this->postJson('/api/suppliers', ['name' => 'Acme Supplies', 'phone' => '0500000000'])
            ->assertCreated()->json('data.id');
        $this->putJson("/api/suppliers/{$id}", ['name' => 'Acme Co'])->assertOk()->assertJsonPath('data.name', 'Acme Co');

        $supplier = Supplier::query()->find($id);
        PurchaseOrder::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'supplier_id' => $supplier->getKey()]);

        $this->getJson('/api/inventory/purchase-orders')
            ->assertOk()
            ->assertJsonPath('data.data.0.supplier_name', 'Acme Co')
            ->assertJsonPath('data.data.0.status', 'received');
    }
}
