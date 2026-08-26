<?php

namespace App\Services\Delivery;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Models\DeliveryRequest;
use App\Models\Driver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The delivery request engine: creation, driver assignment, and status advancement.
 *
 * The status flow lives in DeliveryStatusEnum (one source of truth), so this service
 * never hardcodes a transition table. Every meaningful action appends a status-history
 * row, and reaching a terminal state stamps completed_at.
 */
class DeliveryService
{
    public function __construct(private readonly DeliverySettingsService $settingsService) {}

    /**
     * Create a delivery request, record its first history row, and try to auto-assign.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $settings
     */
    public function create(array $data, array $settings): DeliveryRequest
    {
        return DB::transaction(function () use ($data, $settings) {
            $request = DeliveryRequest::query()->create([
                'organization_id' => $data['organization_id'],
                'branch_id' => $data['branch_id'],
                'customer_id' => $data['customer_id'],
                'order_id' => $data['order_id'] ?? null,
                'zone_id' => $data['zone_id'] ?? null,
                'type' => $data['type']->value,
                'status' => DeliveryStatusEnum::Requested->value,
                'fee' => $data['fee'],
                'address' => $data['address'],
                'notes' => $data['notes'] ?? null,
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'source' => $data['source']->value,
                'created_by_id' => $data['created_by_id'] ?? null,
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
            ]);

            $this->recordHistory(
                $request,
                null,
                $request->status,
                $data['created_by_id'] ?? null,
                $data['source'] === DeliverySourceEnum::Portal ? __('api.delivery_created_portal') : __('api.delivery_created'),
            );

            $this->maybeAutoAssign($request, $settings);

            return $request->refresh();
        });
    }

    /**
     * Drivers eligible to serve the branch, per the enabled methods.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $settings
     */
    public function assignableDrivers(array $branchIds, array $settings): Builder
    {
        $self = $this->settingsService->methodEnabled($settings, 'selfDelivery');
        $platform = $this->settingsService->methodEnabled($settings, 'platformDriver');

        $query = Driver::query()->active();

        if (! $self && ! $platform) {
            // Nothing enabled: no eligible drivers.
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($self, $platform, $branchIds) {
            if ($self) {
                $q->orWhere(fn (Builder $sub) => $sub->where('is_platform', false)->whereIn('branch_id', $branchIds));
            }

            if ($platform) {
                $q->orWhere('is_platform', true);
            }
        });
    }

    /**
     * Auto-assign the least-loaded eligible driver, unless manual assignment is on.
     *
     * @param  array<string, mixed>  $settings
     */
    public function maybeAutoAssign(DeliveryRequest $request, array $settings): void
    {
        if ($this->settingsService->workflow($settings, 'manualAssign')) {
            return;
        }

        $driver = $this->leastLoadedDriver([$request->branch_id], $settings);

        if ($driver !== null) {
            $this->assign($request, $driver, $settings);
        }
    }

    /**
     * Assign a driver, advancing a still-Requested trip to Assigned. If acceptance is
     * not required the trip is pre-accepted.
     *
     * @param  array<string, mixed>  $settings
     */
    public function assign(DeliveryRequest $request, Driver $driver, array $settings): DeliveryRequest
    {
        return DB::transaction(function () use ($request, $driver, $settings) {
            $from = $request->status;

            $attributes = [
                'driver_id' => $driver->getKey(),
                // Assigning a real driver clears any external-app assignment.
                'external_provider' => null,
                'external_ref' => null,
                'rejected_at' => null,
                'reject_reason' => null,
            ];

            // Only a Requested trip is advanced; a higher state keeps its status.
            if ($request->status === DeliveryStatusEnum::Requested) {
                $attributes['status'] = DeliveryStatusEnum::Assigned->value;
                $attributes['assigned_at'] = Carbon::now();
            }

            if (! $this->settingsService->workflow($settings, 'requireAcceptance')) {
                $attributes['accepted_at'] = Carbon::now();
            }

            $request->forceFill($attributes)->save();

            $this->recordHistory($request, $from, $request->status, null, __('api.delivery_assigned', ['name' => $driver->name]));

            return $request->refresh();
        });
    }

    /**
     * Assign the trip to an external delivery app (method 3), with auto-accept.
     */
    public function assignExternal(DeliveryRequest $request, string $provider, ?string $ref, ?float $fee): DeliveryRequest
    {
        return DB::transaction(function () use ($request, $provider, $ref, $fee) {
            $from = $request->status;

            $attributes = [
                'external_provider' => $provider,
                'external_ref' => $ref,
                'driver_id' => null,
                'assigned_at' => Carbon::now(),
                'accepted_at' => Carbon::now(),
                'rejected_at' => null,
                'reject_reason' => null,
            ];

            if ($request->status === DeliveryStatusEnum::Requested) {
                $attributes['status'] = DeliveryStatusEnum::Assigned->value;
            }

            if ($fee !== null) {
                $attributes['fee'] = round($fee, 2);
            }

            $request->forceFill($attributes)->save();

            $this->recordHistory($request, $from, $request->status, null, __('api.delivery_external_assigned', ['provider' => $provider]));

            return $request->refresh();
        });
    }

    /**
     * Advance a trip to a new status, validating it against the flow for its type.
     */
    public function advance(DeliveryRequest $request, DeliveryStatusEnum $target, ?int $userId, ?string $note = null): DeliveryRequest
    {
        if (! $request->status->canTransitionTo($target, $request->type)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_invalid_transition'));
        }

        return DB::transaction(function () use ($request, $target, $userId, $note) {
            $from = $request->status;

            $attributes = ['status' => $target->value];

            if ($target->isTerminal($request->type)) {
                $attributes['completed_at'] = Carbon::now();
            }

            $request->forceFill($attributes)->save();

            $this->recordHistory($request, $from, $target, $userId, $note ?? __('api.delivery_status_changed'));

            return $request->refresh();
        });
    }

    /**
     * Append a status-history row.
     */
    public function recordHistory(DeliveryRequest $request, ?DeliveryStatusEnum $from, DeliveryStatusEnum $to, ?int $userId, string $note): void
    {
        $request->history()->create([
            'user_id' => $userId,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'note' => $note,
            'at' => Carbon::now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The eligible driver with the fewest open trips, oldest first on a tie.
     *
     * @param  array<int, int>  $branchIds
     * @param  array<string, mixed>  $settings
     */
    private function leastLoadedDriver(array $branchIds, array $settings): ?Driver
    {
        /** @var Collection<int, Driver> $drivers */
        $drivers = $this->assignableDrivers($branchIds, $settings)->orderBy('id')->get();

        if ($drivers->isEmpty()) {
            return null;
        }

        $loads = $this->settingsService->openLoads($drivers->pluck('id')->all());

        return $drivers->sortBy(fn (Driver $driver) => $loads[$driver->getKey()] ?? 0)->first();
    }
}
