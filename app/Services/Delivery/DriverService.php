<?php

namespace App\Services\Delivery;

use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
use App\Models\DeliveryRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Driver-app operations on a delivery request: accept, reject, arrive, proof photo, and
 * status advancement with the completion gates the driver must satisfy.
 */
class DriverService
{
    private const MIN_IMAGE_BYTES = 100;

    private const MAX_IMAGE_BYTES = 6 * 1024 * 1024;

    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly DeliverySettingsService $settingsService,
    ) {}

    /**
     * Accept an assigned trip. Only valid before work starts.
     */
    public function accept(DeliveryRequest $request): DeliveryRequest
    {
        $this->assertAssigned($request, 'api.delivery_cannot_accept');

        $request->forceFill(['accepted_at' => Carbon::now(), 'rejected_at' => null, 'reject_reason' => null])->save();
        $this->delivery->recordHistory($request, $request->status, $request->status, null, __('api.delivery_accepted'));

        return $request->refresh();
    }

    /**
     * Reject an assigned trip, returning it to the queue. Only valid before work starts.
     */
    public function reject(DeliveryRequest $request, ?string $reason): DeliveryRequest
    {
        $this->assertAssigned($request, 'api.delivery_cannot_reject');

        $from = $request->status;

        $request->forceFill([
            'driver_id' => null,
            'status' => DeliveryStatusEnum::Requested->value,
            'assigned_at' => null,
            'rejected_at' => Carbon::now(),
            'reject_reason' => $reason,
        ])->save();

        $this->delivery->recordHistory($request, $from, DeliveryStatusEnum::Requested, null, __('api.delivery_rejected'));

        return $request->refresh();
    }

    public function arrive(DeliveryRequest $request): DeliveryRequest
    {
        $request->forceFill(['arrived_at' => Carbon::now()])->save();
        $this->delivery->recordHistory($request, $request->status, $request->status, null, __('api.delivery_arrived'));

        return $request->refresh();
    }

    /**
     * Store a proof photo (pickup or delivery), keeping only the filename.
     */
    public function storePhoto(DeliveryRequest $request, string $kind, string $image): DeliveryRequest
    {
        $bytes = $this->decodeImage($image);
        $filename = $this->persist($bytes);

        $column = $kind === 'pickup' ? 'pickup_photo_url' : 'delivery_photo_url';
        $request->forceFill([$column => $filename])->save();

        return $request->refresh();
    }

    /**
     * Advance the trip, enforcing acceptance, photo-proof, and invoice-approval gates.
     */
    public function advance(DeliveryRequest $request, DeliveryStatusEnum $target): DeliveryRequest
    {
        $settings = $this->settingsService->resolve($request->organization_id);

        // 1. Acceptance gate.
        if ($this->settingsService->workflow($settings, 'requireAcceptance') && $request->accepted_at === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_accept_first'));
        }

        // 2. Photo-proof gate.
        if ($this->settingsService->workflow($settings, 'photoProof')) {
            $this->assertPhoto($request, $target);
        }

        // 3. Invoice-approval gate on delivery completion.
        if ($target === DeliveryStatusEnum::Delivered && $request->invoice_approval_required && $request->invoice_approved_at === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_awaiting_invoice_approval'));
        }

        return $this->delivery->advance($request, $target, null);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function assertAssigned(DeliveryRequest $request, string $messageKey): void
    {
        abort_if($request->status !== DeliveryStatusEnum::Assigned, Response::HTTP_UNPROCESSABLE_ENTITY, __($messageKey));
    }

    private function assertPhoto(DeliveryRequest $request, DeliveryStatusEnum $target): void
    {
        $needsPickup = $request->type === DeliveryTypeEnum::Pickup
            && $target === DeliveryStatusEnum::PickedUp
            && $request->pickup_photo_url === null;

        $needsDelivery = $request->type === DeliveryTypeEnum::Delivery
            && $target === DeliveryStatusEnum::Delivered
            && $request->delivery_photo_url === null;

        if ($needsPickup) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_pickup_photo_required'));
        }

        if ($needsDelivery) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_photo_required'));
        }
    }

    private function decodeImage(string $image): string
    {
        // Strip a data-URL prefix if present.
        if (str_contains($image, ',')) {
            $image = substr($image, strpos($image, ',') + 1);
        }

        $bytes = base64_decode($image, true);

        if ($bytes === false) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_invalid_image'));
        }

        $length = strlen($bytes);
        $isJpeg = str_starts_with($bytes, "\xFF\xD8\xFF");
        $isPng = str_starts_with($bytes, "\x89PNG\r\n\x1a\n");

        if ($length < self::MIN_IMAGE_BYTES || $length > self::MAX_IMAGE_BYTES || (! $isJpeg && ! $isPng)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.delivery_invalid_image'));
        }

        return $bytes;
    }

    private function persist(string $bytes): string
    {
        $extension = str_starts_with($bytes, "\x89PNG\r\n\x1a\n") ? 'png' : 'jpg';
        $filename = Str::uuid()->toString().'.'.$extension;

        Storage::disk('local')->put('delivery-photos/'.$filename, $bytes);

        return $filename;
    }
}
