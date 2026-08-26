<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\WaMessage;
use Illuminate\Http\JsonResponse;

/**
 * The organization's outbound message log (read-only), sourced from wa_messages.
 */
class NotificationLogController extends TenantController
{
    private const HIDDEN_OTP = '•••• رمز تحقق (مخفي)';

    public function index(): JsonResponse
    {
        $this->staff();

        $messages = WaMessage::query()
            ->where('organization_id', $this->organizationId())
            ->with('customer:id,name,phone')
            ->latest('id')
            ->limit(100)
            ->get();

        $data = $messages->map(fn (WaMessage $m) => [
            'id' => $m->getKey(),
            'customer_name' => $m->customer?->name,
            'customer_phone' => $m->customer?->phone,
            'channel' => $m->channel,
            'event' => $m->event_key,
            'body' => ($m->category === WaCategoryEnum::Authentication || $m->event_key === WaEventEnum::Otp->value) ? self::HIDDEN_OTP : $m->body,
            'status' => $m->status->value,
            'sent_at' => $m->sent_at,
            'created_at' => $m->created_at,
        ]);

        return successResponse($data);
    }
}
