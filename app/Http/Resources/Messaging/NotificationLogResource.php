<?php

namespace App\Http\Resources\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One outbound message as the notification log shows it.
 *
 * A verification code is never rendered back: the log is readable by any staff member, and
 * a code sitting in it would let someone approve a wallet payment they never had consent
 * for. Redaction lives here rather than at the query, so the stored message stays intact
 * for auditing.
 */
class NotificationLogResource extends JsonResource
{
    private const REDACTED_OTP = '•••• رمز تحقق (مخفي)';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'customer_name' => $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_phone' => $this->whenLoaded('customer', fn () => $this->customer?->phone),

            'channel' => $this->channel,
            'event' => $this->event_key,
            'body' => $this->carriesCode() ? self::REDACTED_OTP : $this->body,

            'status' => $this->status,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }

    private function carriesCode(): bool
    {
        return $this->category === WaCategoryEnum::Authentication
            || $this->event_key === WaEventEnum::Otp->value;
    }
}
