<?php

namespace App\Http\Controllers\API\Tenancy\Inventory;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Inventory\SupplierRequest;
use App\Http\Resources\Inventory\SupplierResource;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;

class SupplierController extends TenantController
{
    public function index(): JsonResponse
    {

        $suppliers = Supplier::query()
            ->forOrganization($this->organizationId())
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'address']);

        return successResponse($suppliers);
    }

    public function store(SupplierRequest $request): JsonResponse
    {

        $supplier = Supplier::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse(new SupplierResource($supplier), __('api.created_success'), 201);
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->assertOwned($supplier);

        $supplier->update($request->validated());

        return successResponse(new SupplierResource($supplier->refresh()), __('api.updated_success'));
    }
}
