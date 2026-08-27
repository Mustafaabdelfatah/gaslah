<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\SetBooksLockRequest;
use App\Services\Accounting\BooksLockService;
use Illuminate\Http\JsonResponse;

class BooksLockController extends TenantController
{
    public function __construct(private readonly BooksLockService $booksLock)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {

        return successResponse([
            'closed_through' => $this->booksLock->closedThrough($this->organizationId())?->toDateString(),
        ]);
    }

    /**
     * Set or clear the period lock. Owner-only, since it can reopen closed books.
     */
    public function update(SetBooksLockRequest $request): JsonResponse
    {

        $lock = $this->booksLock->setClosedThrough(
            $this->organizationId(),
            $request->closedThrough(),
            $this->staff()->getKey(),
        );

        return successResponse([
            'closed_through' => $lock->closed_through?->toDateString(),
        ], __('api.updated_success'));
    }
}
