<?php

namespace App\Http\Controllers\API\Messaging;

use App\Http\Controllers\API\BaseController;
use App\Models\WaMessage;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The public WhatsApp webhook. Verification echoes Meta's challenge; status receipts are
 * HMAC-verified fail-closed — with no app secret configured, every receipt is refused
 * (accepting unverifiable bodies would let an attacker flip any message's status). Always
 * answers 200 for anything processed or ignored so Meta does not retry forever.
 */
class WaWebhookController extends BaseController
{
    /**
     * Meta subscription verification.
     */
    public function verify(Request $request): Response
    {
        $expected = config('services.whatsapp.webhook_verify_token');

        if ($request->query('hub_mode') === 'subscribe'
            && ! empty($expected)
            && hash_equals((string) $expected, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'));
        }

        return response('', 403);
    }

    /**
     * Status receipts.
     */
    public function receive(Request $request): JsonResponse
    {
        $secret = config('services.whatsapp.app_secret');
        abort_if(empty($secret), 403, __('api.wa_webhook_not_configured'));

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), (string) $secret);
        abort_unless(hash_equals($expected, (string) $request->header('X-Hub-Signature-256')), 403, __('api.wa_webhook_bad_signature'));

        foreach ($this->statuses($request->all()) as $status) {
            $this->applyStatus($status);
        }

        return successResponse(['received' => true]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, array<string, mixed>>
     */
    private function statuses(array $payload): array
    {
        $statuses = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                foreach ($change['value']['statuses'] ?? [] as $status) {
                    $statuses[] = $status;
                }
            }
        }

        return $statuses;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function applyStatus(array $status): void
    {
        $id = $status['id'] ?? null;
        if ($id === null) {
            return;
        }

        $message = WaMessage::query()->where('provider_message_id', $id)->first();
        if ($message === null) {
            return; // Unknown id — another number may share this webhook.
        }

        $now = CarbonImmutable::now();

        match ($status['status'] ?? null) {
            'sent' => $message->status->value === 'queued' ? $message->forceFill(['status' => 'sent', 'sent_at' => $now])->save() : null,
            'delivered' => $message->forceFill(['status' => 'delivered', 'delivered_at' => $now])->save(),
            'read' => $message->forceFill(['status' => 'read', 'read_at' => $now])->save(),
            'failed' => $message->forceFill(['status' => 'failed', 'error' => $status['errors'][0]['title'] ?? __('api.wa_send_failed')])->save(),
            default => null,
        };
    }
}
