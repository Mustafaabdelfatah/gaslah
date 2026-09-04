<?php

namespace App\Services\Delivery;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Services\Orders\PosService;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Orchestrates the staff-facing delivery request lifecycle on top of the primitives in
 * DeliveryService: creation (expanding Both into two trips in one transaction), driver
 * and external-app assignment with their gates, status submission with the completion
 * gates a staff member may not bypass, basket inventory (reusing the POS pricing path so
 * a basket is priced from the catalogue, never from a client price), and dashboard stats.
 */
class DeliveryRequestService
{
    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly DeliverySettingsService $settingsService,
        private readonly PosService $pos,
    ) {}

    /**
     * Create one or two trips (Both expands to Pickup + Delivery) in one transaction.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $settings
     * @return Collection<int, DeliveryRequest>
     */
    public function createRequests(
        int $organizationId,
        int $branchId,
        Customer $customer,
        string $type,
        ?DeliveryZone $zone,
        array $data,
        array $settings,
        DeliverySourceEnum $source,
        ?int $createdById,
    ): Collection {
        $types = $type === 'both'
            ? [DeliveryTypeEnum::Pickup, DeliveryTypeEnum::Delivery]
            : [DeliveryTypeEnum::from($type)];

        return DB::transaction(function () use ($types, $organizationId, $branchId, $customer, $zone, $data, $settings, $source, $createdById) {
            return collect($types)->map(fn (DeliveryTypeEnum $tripType) => $this->delivery->create([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'customer_id' => $customer->getKey(),
                'order_id' => $data['order_id'] ?? null,
                'zone_id' => $zone?->getKey(),
                'type' => $tripType,
                'fee' => $this->settingsService->feeFor($settings, $tripType, $zone),
                'address' => $data['address'],
                'notes' => $data['notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'source' => $source,
                'created_by_id' => $createdById,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ], $settings));
        });
    }

    /**
     * Manually assign a driver, refusing one outside the eligible set.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $settings
     */
    public function assignDriver(DeliveryRequest $request, int $driverId, array $branchIds, array $settings): DeliveryRequest
    {
        $driver = $this->delivery->assignableDrivers($branchIds, $settings)->whereKey($driverId)->first();

        abort_if($driver === null, Response::HTTP_NOT_FOUND, __('api.record_not_found'));

        /** @var Driver $driver */
        return $this->delivery->assign($request, $driver, $settings);
    }

    /**
     * Assign to an external delivery app (method 3), gated by the integration method.
     *
     * @param  array<string, mixed>  $settings
     */
    public function assignExternal(DeliveryRequest $request, string $provider, ?string $ref, ?float $fee, array $settings): DeliveryRequest
    {
        if (! $this->settingsService->methodEnabled($settings, 'integration')) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_integration_disabled'));
        }

        return $this->delivery->assignExternal($request, $provider, $ref, $fee);
    }

    /**
     * Submit a status, enforcing the completion gates before a delivery is marked
     * delivered — a staff member does not bypass customer approval or photo proof.
     *
     * @param  array<string, mixed>  $settings
     */
    public function submitStatus(DeliveryRequest $request, DeliveryStatusEnum $target, int $userId, array $settings): DeliveryRequest
    {
        if ($target === DeliveryStatusEnum::Delivered) {
            $this->assertDeliveryCompletable($request, $settings);
        }

        return $this->delivery->advance($request, $target, $userId);
    }

    /**
     * Stamp the driver's arrival at the customer's location.
     */
    public function arrive(DeliveryRequest $request, int $userId): DeliveryRequest
    {
        $request->forceFill(['arrived_at' => Carbon::now()])->save();
        $this->delivery->recordHistory($request, $request->status, $request->status, $userId, __('api.delivery_arrived'));

        return $request->refresh();
    }

    /**
     * Flag whether the request needs customer invoice approval before delivery.
     */
    public function requireInvoiceApproval(DeliveryRequest $request, bool $require, int $userId): DeliveryRequest
    {
        $request->forceFill(['invoice_approval_required' => $require])->save();
        $this->delivery->recordHistory($request, $request->status, $request->status, $userId, __('api.delivery_invoice_approval_set'));

        return $request->refresh();
    }

    /**
     * Turn the picked-up basket into a priced invoice (one per request), reusing the POS
     * pricing path so every line is re-priced from the catalogue.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  array<string, mixed>  $settings
     */
    public function inventory(DeliveryRequest $request, Branch $branch, array $items, ?string $notes, int $cashierId, array $settings): DeliveryRequest
    {
        abort_if($request->order_id !== null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_already_invoiced'));

        $order = $this->pos->create($request->organization_id, $branch, $cashierId, [
            'customer_id' => $request->customer_id,
            'items' => $items,
            'notes' => __('api.delivery_invoice_note'),
        ]);

        // Best-effort ledger sync, as with a POS sale.
        $this->pos->postAccounting($order);

        $needsApproval = $this->settingsService->workflow($settings, 'invoiceApproval');

        $request->forceFill([
            'order_id' => $order->getKey(),
            'inventory_done_at' => Carbon::now(),
            'inventory_notes' => $notes,
            'invoice_approval_required' => $needsApproval,
        ])->save();

        $this->delivery->recordHistory($request, $request->status, $request->status, $cashierId, __('api.delivery_inventory_done'));

        return $request->refresh();
    }

    /**
     * Dashboard statistics over a 90-day window.
     *
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function stats(array $branchIds): array
    {
        $since = Carbon::now()->subDays(90);
        $row = DeliveryRequest::query()
            ->inBranches($branchIds)
            ->selectRaw(
                'SUM(CASE WHEN status = ? AND driver_id IS NULL THEN 1 ELSE 0 END) as pending_assignment,
                 SUM(CASE WHEN type = ? AND status IN (?, ?) THEN 1 ELSE 0 END) as in_pickup,
                 SUM(CASE WHEN type = ? AND status IN (?, ?, ?) THEN 1 ELSE 0 END) as in_delivery,
                 SUM(CASE WHEN created_at >= ? AND status = ? THEN 1 ELSE 0 END) as delivered,
                 SUM(CASE WHEN created_at >= ? AND status = ? THEN 1 ELSE 0 END) as cancelled,
                 SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) as total',
                [
                    DeliveryStatusEnum::Requested->value,
                    DeliveryTypeEnum::Pickup->value,
                    DeliveryStatusEnum::Assigned->value,
                    DeliveryStatusEnum::PickedUp->value,
                    DeliveryTypeEnum::Delivery->value,
                    DeliveryStatusEnum::Assigned->value,
                    DeliveryStatusEnum::PickedUp->value,
                    DeliveryStatusEnum::OutForDelivery->value,
                    $since,
                    DeliveryStatusEnum::Delivered->value,
                    $since,
                    DeliveryStatusEnum::Cancelled->value,
                    $since,
                ],
            )
            ->first();

        return [
            'pending_assignment' => (int) $row->pending_assignment,
            'in_pickup' => (int) $row->in_pickup,
            'in_delivery' => (int) $row->in_delivery,
            'delivered' => (int) $row->delivered,
            'cancelled' => (int) $row->cancelled,
            'total' => (int) $row->total,
            'active_drivers' => Driver::query()->active()->where('is_platform', false)->whereIn('branch_id', $branchIds)->count(),
            'avg_delivery_minutes' => $this->avgDeliveryMinutes($branchIds, $since),
            'avg_service_minutes' => $this->avgServiceMinutes($branchIds, $since),
            'window_days' => 90,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function assertDeliveryCompletable(DeliveryRequest $request, array $settings): void
    {
        if ($request->invoice_approval_required && $request->invoice_approved_at === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_awaiting_invoice_approval'));
        }

        $photoNeeded = $this->settingsService->workflow($settings, 'photoProof')
            && $request->type === DeliveryTypeEnum::Delivery
            && $request->external_provider === null
            && $request->delivery_photo_url === null;

        if ($photoNeeded) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_photo_required'));
        }
    }

    /**
     * Average assigned→delivered minutes for delivered trips in the window.
     *
     * @param  array<int, int>  $branchIds
     */
    private function avgDeliveryMinutes(array $branchIds, Carbon $since): ?float
    {
        $query = DeliveryRequest::query()
            ->inBranches($branchIds)
            ->where('type', DeliveryTypeEnum::Delivery->value)
            ->where('status', DeliveryStatusEnum::Delivered->value)
            ->whereNotNull('assigned_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $since);

        return $this->averageMinutes($query, 'assigned_at', 'completed_at');
    }

    /**
     * Average pickup-requested → delivery-completed minutes for orders that went
     * through both legs. An unpaired trip must not distort the full-service KPI.
     *
     * @param  array<int, int>  $branchIds
     */
    private function avgServiceMinutes(array $branchIds, Carbon $since): ?float
    {
        $pickupStarts = DeliveryRequest::query()
            ->inBranches($branchIds)
            ->where('type', DeliveryTypeEnum::Pickup->value)
            ->whereNotNull('order_id')
            ->selectRaw('order_id, MIN(created_at) as pickup_started_at')
            ->groupBy('order_id');

        $query = DB::table('delivery_requests as deliveries')
            ->joinSub($pickupStarts, 'pickups', 'pickups.order_id', '=', 'deliveries.order_id')
            ->whereIn('deliveries.branch_id', $branchIds)
            ->where('deliveries.type', DeliveryTypeEnum::Delivery->value)
            ->where('deliveries.status', DeliveryStatusEnum::Delivered->value)
            ->whereNotNull('deliveries.completed_at')
            ->where('deliveries.completed_at', '>=', $since);

        return $this->averageMinutes($query, 'pickups.pickup_started_at', 'deliveries.completed_at');
    }

    /**
     * Let the database calculate an average instead of hydrating every matching trip.
     */
    private function averageMinutes(EloquentBuilder|QueryBuilder $query, string $startColumn, string $endColumn): ?float
    {
        $duration = match (DB::connection()->getDriverName()) {
            'sqlite' => "(julianday({$endColumn}) - julianday({$startColumn})) * 1440.0",
            'pgsql' => "EXTRACT(EPOCH FROM ({$endColumn} - {$startColumn})) / 60.0",
            'sqlsrv' => "DATEDIFF(second, {$startColumn}, {$endColumn}) / 60.0",
            default => "TIMESTAMPDIFF(SECOND, {$startColumn}, {$endColumn}) / 60.0",
        };

        $row = $query
            ->whereColumn($endColumn, '>=', $startColumn)
            ->selectRaw("AVG({$duration}) as average_minutes")
            ->first();

        return $row?->average_minutes === null ? null : round((float) $row->average_minutes, 1);
    }
}
