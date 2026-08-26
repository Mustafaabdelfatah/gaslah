<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Accounting\BooksLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BooksLockController extends TenantController
{
    public function __construct(private readonly BooksLockService $booksLock)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        $this->requireManager();

        return successResponse([
            'closed_through' => $this->booksLock->closedThrough($this->organizationId())?->toDateString(),
        ]);
    }

    /**
     * Set or clear the period lock. Owner-only, since it can reopen closed books.
     */
    public function update(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $data = $request->validate([
            'closed_through' => ['nullable', 'date'],
        ]);

        $lock = $this->booksLock->setClosedThrough(
            $this->organizationId(),
            $data['closed_through'] ?? null,
            $this->staff()->getKey(),
        );

        return successResponse([
            'closed_through' => $lock->closed_through?->toDateString(),
        ], __('api.updated_success'));
    }
}
