<?php

namespace App\Http\Controllers\API\Tenancy\Delivery;

use App\Enum\Orders\OrderStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Delivery\DisplayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-store display screen: a staff member mints a signed link for a branch, and the
 * public board reads the ready and in-progress orders behind that link with strict data
 * minimisation (first name only, no phones or totals).
 */
class DisplayController extends TenantController
{
    private const MAX_CARDS = 12;

    public function __construct(private readonly DisplayTokenService $tokens)
    {
        parent::__construct();
    }

    /**
     * Mint a display link for the caller's branch.
     */
    public function token(Request $request): JsonResponse
    {
        $this->staff();

        $branchId = (int) ($request->input('branch_id') ?? $this->writeBranchId());
        abort_unless(in_array($branchId, $this->readBranchIds(), true), 403, __('api.branch_not_available'));

        $branch = Branch::query()->find($branchId);
        $token = $this->tokens->mint($branchId);

        return successResponse([
            'token' => $token,
            'path' => '/display/'.$token,
            'branch' => $branch?->name,
        ]);
    }

    /**
     * The public board for a signed display link. A forged/stale link yields
     * valid:false with 200 so the screen can show a friendly message.
     */
    public function show(string $token): JsonResponse
    {
        $branchId = $this->tokens->verify($token);

        $branch = $branchId === null ? null : Branch::query()->with('organization:id,name')->find($branchId);

        if ($branch === null) {
            return successResponse(['valid' => false]);
        }

        $ready = Order::query()
            ->where('branch_id', $branchId)
            ->where('status', OrderStatusEnum::Ready->value)
            ->whereNull('archived_at')
            ->with('customer:id,name')
            ->latest('updated_at')
            ->limit(self::MAX_CARDS)
            ->get();

        $processing = Order::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', [OrderStatusEnum::Received->value, OrderStatusEnum::Processing->value])
            ->whereNull('archived_at')
            ->with('customer:id,name')
            ->oldest('created_at')
            ->limit(self::MAX_CARDS)
            ->get();

        return successResponse([
            'valid' => true,
            'branch' => ['name' => $branch->name, 'organization' => $branch->organization?->name],
            'ready' => $ready->map(fn (Order $order) => $this->card($order)),
            'processing' => $processing->map(fn (Order $order) => $this->card($order)),
        ]);
    }

    /**
     * A minimal, public-safe card: no phone, no total, first name only.
     *
     * @return array<string, mixed>
     */
    private function card(Order $order): array
    {
        $orderNo = (string) $order->order_no;
        $name = (string) ($order->customer?->name ?? '');

        return [
            'id' => $order->getKey(),
            'order_no' => $orderNo,
            'short_no' => str_contains($orderNo, '-') ? substr(strrchr($orderNo, '-'), 1) : $orderNo,
            'status' => $order->status->value,
            'first_name' => $name === '' ? '' : explode(' ', trim($name))[0],
        ];
    }
}
