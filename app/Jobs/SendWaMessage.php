<?php

namespace App\Jobs;

use App\Enum\Messaging\WaMessageStatusEnum;
use App\Models\WaMessage;
use App\Services\Messaging\WaService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a queued WhatsApp message via the resolved provider.
 *
 * Idempotent: it exits unless the row is still QUEUED. A LOGGED/SENT result finalises the
 * row to SENT; anything else retries with backoff, then FAILED. failed() is the safety net
 * that closes any row left QUEUED after a timeout/kill so every message ends in a terminal
 * state.
 */
class SendWaMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 45;

    public function __construct(public int $messageId) {}

    public function handle(WaService $wa): void
    {
        $message = WaMessage::query()->find($this->messageId);

        if ($message === null || $message->status !== WaMessageStatusEnum::Queued) {
            return;
        }

        $result = $wa->provider($message->organization_id)->send($message->to_phone, $message->body, $message->channel);

        if (in_array($result['status'], ['sent', 'logged'], true)) {
            $message->forceFill([
                'status' => WaMessageStatusEnum::Sent->value,
                'provider_message_id' => $result['id'] ?? null,
                'sent_at' => CarbonImmutable::now(),
            ])->save();

            return;
        }

        // A real send failure: let the queue retry, or fail out on the last attempt.
        if ($this->attempts() >= $this->tries) {
            $message->forceFill(['status' => WaMessageStatusEnum::Failed->value, 'error' => __('api.wa_send_failed')])->save();

            return;
        }

        $this->release(30);
    }

    public function failed(\Throwable $exception): void
    {
        WaMessage::query()
            ->whereKey($this->messageId)
            ->where('status', WaMessageStatusEnum::Queued->value)
            ->update(['status' => WaMessageStatusEnum::Failed->value, 'error' => __('api.wa_send_failed')]);
    }
}
