<?php

namespace App\Http\Controllers\API\Tenancy\Inventory;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends TenantController
{
    private const FEATURE = 'inventory';

    public function index(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $suppliers = Supplier::query()
            ->forOrganization($this->organizationId())
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'address']);

        return successResponse($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $supplier = Supplier::query()->create([
            ...$this->validated($request),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse($supplier, __('api.created_success'), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        abort_unless($supplier->organization_id === $this->organizationId(), 404, __('api.record_not_found'));

        $supplier->update($this->validated($request, updating: true));

        return successResponse($supplier->refresh(), __('api.updated_success'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
