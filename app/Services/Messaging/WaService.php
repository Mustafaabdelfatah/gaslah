<?php

namespace App\Services\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Enum\Messaging\WaMessageStatusEnum;
use App\Jobs\SendWaMessage;
use App\Models\MessagingSetting;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\Messaging\Providers\MessagingProvider;
use App\Services\Messaging\Providers\WhatsAppProvider;
use BackedEnum;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The single gate for every WhatsApp/SMS message in the system.
 *
 * Every message passes a six-layer commercial gate plus the monthly org/branch quota, is
 * recorded in a wa_messages row (the source of truth for quotas and stats), then pushed to
 * a queue. Sending is best-effort: a commercial block records a BLOCKED row and returns it
 * rather than throwing, so it never fails the originating operation. A queued row counts
 * toward the quota immediately, so the count-and-insert is serialized per organization.
 */
class WaService
{
    /**
     * Built-in Arabic fallback templates, used when no custom or platform template exists.
     */
    private const FALLBACK_TEMPLATES = [
        'order_created' => 'مرحباً {name}، تم استلام طلبك رقم {orderNo} في {org}.',
        'order_ready' => 'طلبك رقم {orderNo} جاهز للاستلام من {org}.',
        'order_completed' => 'تم تسليم طلبك رقم {orderNo}. شكراً لتعاملك مع {org}.',
        'otp' => 'رمز التحقق الخاص بك هو {code}.',
        'invoice' => 'فاتورة طلبك رقم {orderNo} بمبلغ {total}. {org}',
        'delivery' => 'تحديث توصيل طلبك رقم {orderNo}: {status}.',
        'test' => 'رسالة تجريبية من {org} — كل شيء يعمل.',
    ];

    private const DEFAULT_ORG_QUOTA = 1000;

    /**
     * Trigger an automatic event: resolve and render the template, then queue. Best-effort
     * — any failure is reported and never breaks the caller.
     *
     * @param  array<string, mixed>  $vars
     * @param  array<string, mixed>  $context
     */
    public function trigger(?int $organizationId, WaEventEnum $event, ?string $phone, array $vars, array $context = []): void
    {
        try {
            if ($phone === null || $phone === '') {
                return;
            }

            $body = $this->render($this->resolveTemplate($organizationId, $event), $vars);

            $this->queue([
                'organization_id' => $organizationId,
                'branch_id' => $context['branch_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'order_id' => $context['order_id'] ?? null,
                'to_phone' => $phone,
                'category' => $event->category(),
                'event_key' => $event->value,
                'body' => $body,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Queue one message through the gate and quota. Returns the recorded row (QUEUED or
     * BLOCKED).
     *
     * @param  array<string, mixed>  $data
     */
    public function queue(array $data): WaMessage
    {
        $organizationId = $data['organization_id'] ?? null;
        $category = $data['category'] instanceof WaCategoryEnum ? $data['category'] : WaCategoryEnum::from($data['category']);
        $eventKey = $data['event_key'];

        $phone = $this->normalizePhone((string) $data['to_phone']);
        if ($phone === null) {
            return $this->record($data, $category, WaMessageStatusEnum::Blocked, __('api.wa_invalid_phone'));
        }
        $data['to_phone'] = $phone;

        // A platform-level message (no org) skips the org quota and lock entirely.
        if ($organizationId === null) {
            return $this->dispatchQueued($this->record($data, $category, WaMessageStatusEnum::Queued));
        }

        return $this->serializePerOrg($organizationId, function () use ($data, $organizationId, $category, $eventKey) {
            return DB::transaction(function () use ($data, $organizationId, $category, $eventKey) {
                $blocked = $this->gate($organizationId, $data['branch_id'] ?? null, $category, $eventKey);

                if ($blocked !== null) {
                    return $this->record($data, $category, WaMessageStatusEnum::Blocked, $blocked);
                }

                $message = $this->record($data, $category, WaMessageStatusEnum::Queued);
                $this->dispatchQueued($message);

                return $message;
            });
        });
    }

    /**
     * The commercial gate. Returns null to allow, or an Arabic block reason.
     */
    public function gate(?int $organizationId, ?int $branchId, WaCategoryEnum $category, string $eventKey): ?string
    {
        // Platform messages are always allowed.
        if ($organizationId === null) {
            return null;
        }

        $isAuth = $category === WaCategoryEnum::Authentication;
        $limits = $this->limits($organizationId);
        $config = $this->config($organizationId);

        // 1. Global platform switch — never blocks authentication codes.
        if (! config('messaging.platform_enabled', true) && ! $isAuth) {
            return __('api.wa_platform_off');
        }

        // 2. Per-org switch set by the platform.
        if (($limits['enabled'] ?? true) === false) {
            return __('api.wa_org_disabled_by_platform');
        }

        // 3. Category allow-list.
        if (($limits['categories'][$category->value] ?? true) === false) {
            return __('api.wa_category_off', ['category' => $category->value]);
        }

        // 4. Event allow-list.
        if (($limits['allowed_events'][$eventKey] ?? true) === false) {
            return __('api.wa_event_not_allowed');
        }

        // 5. Quiet hours (marketing only).
        if ($category === WaCategoryEnum::Marketing && $this->inQuietHours($limits)) {
            return __('api.wa_quiet_hours');
        }

        // 6. Organization's own settings.
        if (($config['enabled'] ?? true) === false) {
            return __('api.wa_org_disabled');
        }
        if (($config['events'][$eventKey] ?? true) === false) {
            return __('api.wa_event_disabled');
        }

        // Quotas.
        $monthlyLimit = (int) ($limits['monthly_limit'] ?? self::DEFAULT_ORG_QUOTA);
        if ($monthlyLimit > 0 && $this->monthUsed($organizationId, null) >= $monthlyLimit) {
            return __('api.wa_org_quota_reached');
        }

        if ($branchId !== null) {
            $branchLimit = (int) ($limits['branch_limits'][$branchId] ?? 0);
            if ($branchLimit > 0 && $this->monthUsed($organizationId, $branchId) >= $branchLimit) {
                return __('api.wa_branch_quota_reached');
            }
        }

        return null;
    }

    /**
     * Count messages this calendar month that consumed quota.
     */
    public function monthUsed(int $organizationId, ?int $branchId): int
    {
        return WaMessage::query()
            ->where('organization_id', $organizationId)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('status', WaMessageStatusEnum::countedValues())
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->count();
    }

    /**
     * Resolve a template body by priority: active org template → active platform default →
     * built-in fallback.
     */
    public function resolveTemplate(?int $organizationId, WaEventEnum $event): string
    {
        if ($organizationId !== null) {
            $org = WaTemplate::query()->active()->where('organization_id', $organizationId)->where('event_key', $event->value)->latest('id')->first();
            if ($org !== null) {
                return $org->body;
            }
        }

        $platform = WaTemplate::query()->active()->whereNull('organization_id')->where('event_key', $event->value)->latest('id')->first();

        return $platform?->body ?? self::FALLBACK_TEMPLATES[$event->value] ?? '';
    }

    /**
     * Render {var} placeholders; an unknown variable renders as empty.
     *
     * @param  array<string, mixed>  $vars
     */
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback('/\{(\w+)\}/', fn ($m) => (string) ($vars[$m[1]] ?? ''), $template) ?? $template;
    }

    /**
     * The effective sender mode for an organization.
     */
    public function senderMode(int $organizationId): string
    {
        $wa = $this->config($organizationId)['whatsapp'] ?? [];

        return (($wa['mode'] ?? null) === 'custom' && ! empty($wa['token']) && ! empty($wa['phoneId'])) ? 'custom' : 'platform';
    }

    /**
     * The provider for an organization (a per-org custom provider, or the bound default).
     */
    public function provider(?int $organizationId): MessagingProvider
    {
        if ($organizationId !== null && $this->senderMode($organizationId) === 'custom') {
            $wa = $this->config($organizationId)['whatsapp'];

            return new WhatsAppProvider($wa['token'], $wa['phoneId']);
        }

        return app(MessagingProvider::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $data
     */
    private function record(array $data, WaCategoryEnum $category, WaMessageStatusEnum $status, ?string $error = null): WaMessage
    {
        return WaMessage::query()->create([
            'organization_id' => $data['organization_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'order_id' => $data['order_id'] ?? null,
            'to_phone' => $data['to_phone'],
            'channel' => $data['channel'] ?? 'whatsapp',
            'category' => $category->value,
            'event_key' => $data['event_key'],
            'template_id' => $data['template_id'] ?? null,
            'body' => $data['body'],
            'sender_mode' => isset($data['organization_id']) && $data['organization_id'] !== null ? $this->senderMode($data['organization_id']) : 'platform',
            'status' => $status->value,
            'error' => $error,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    private function dispatchQueued(WaMessage $message): WaMessage
    {
        if ($message->status === WaMessageStatusEnum::Queued) {
            SendWaMessage::dispatch($message->getKey())->afterCommit();
        }

        return $message;
    }

    /**
     * The quota picture the overview screen shows: what the org may send this month,
     * what it has sent, and the same per branch.
     *
     * The limits are the platform's to set, so they are reported rather than assumed —
     * a bar with no ceiling tells a tenant nothing.
     *
     * @param  array<int, array{id: int, name: string}>  $branches
     * @return array<string, mixed>
     */
    public function quotaSnapshot(int $organizationId, array $branches): array
    {
        $limits = $this->limits($organizationId);
        $monthlyLimit = (int) ($limits['monthly_limit'] ?? self::DEFAULT_ORG_QUOTA);

        return [
            'org_used' => $this->monthUsed($organizationId, null),
            'org_limit' => $monthlyLimit,
            // The platform can switch a tenant off outright; the screen says so rather
            // than letting every send fail with no explanation.
            'allowed' => ($limits['enabled'] ?? true) !== false && config('messaging.platform_enabled', true),
            'sender_mode' => $this->senderMode($organizationId),

            'branches' => array_map(fn (array $branch) => [
                'id' => $branch['id'],
                'name' => $branch['name'],
                'used' => $this->monthUsed($organizationId, $branch['id']),
                'limit' => (int) ($limits['branch_limits'][$branch['id']] ?? 0),
            ], $branches),
        ];
    }

    /**
     * Messages per calendar month over the last `$months`, oldest first.
     *
     * Bucketed in PHP rather than SQL: month extraction is dialect-specific and this
     * project runs on more than one database.
     *
     * @return array<int, array{month: string, count: int}>
     */
    public function monthlyTrend(int $organizationId, int $months = 6): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);

        $buckets = [];
        for ($i = 0; $i < $months; $i++) {
            $buckets[$start->addMonths($i)->format('Y-m')] = 0;
        }

        WaMessage::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', $start)
            ->get(['created_at'])
            ->each(function (WaMessage $message) use (&$buckets) {
                $key = CarbonImmutable::instance($message->created_at)->format('Y-m');
                if (array_key_exists($key, $buckets)) {
                    $buckets[$key]++;
                }
            });

        return array_map(
            static fn (string $month, int $count) => ['month' => $month, 'count' => $count],
            array_keys($buckets),
            $buckets,
        );
    }

    /**
     * This month's message counts grouped by one column, for the overview screen.
     *
     * @return array<int, array{key: string, count: int}>
     */
    public function monthlyCountsBy(int $organizationId, string $column): array
    {
        return WaMessage::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '>=', CarbonImmutable::now()->startOfMonth())
            ->selectRaw("{$column} as k, COUNT(*) as c")
            ->groupBy($column)
            ->get()
            ->map(fn ($row) => [
                'key' => $row->k instanceof BackedEnum ? $row->k->value : $row->k,
                'count' => (int) $row->c,
            ])
            ->all();
    }

    /**
     * Serialize per-organization quota checks. Uses a MySQL named lock in production; a
     * no-op on other drivers (the test DB is single-connection).
     */
    private function serializePerOrg(int $organizationId, callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $callback();
        }

        DB::selectOne('SELECT GET_LOCK(?, 10) as acquired', ['wa-quota:'.$organizationId]);

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) as released', ['wa-quota:'.$organizationId]);
        }
    }

    /**
     * @param  array<string, mixed>  $limits
     */
    private function inQuietHours(array $limits): bool
    {
        $quiet = $limits['quiet_hours'] ?? null;
        if (! is_array($quiet) || ($quiet['enabled'] ?? false) === false) {
            return false;
        }

        $now = CarbonImmutable::now(config('app.timezone', 'UTC'))->format('H:i');
        $from = $quiet['from'] ?? '22:00';
        $to = $quiet['to'] ?? '07:00';

        // Support a window that wraps past midnight.
        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }

    /**
     * @return array<string, mixed>
     */
    private function config(int $organizationId): array
    {
        return $this->settings($organizationId)->config ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    private function limits(int $organizationId): array
    {
        return $this->settings($organizationId)->limits ?? [];
    }

    private function settings(int $organizationId): MessagingSetting
    {
        return MessagingSetting::query()->firstOrNew(['organization_id' => $organizationId]);
    }

    private function normalizePhone(string $phone): ?string
    {
        $normalized = preg_replace('/[\s\-()]+/', '', trim($phone));

        return ($normalized !== null && preg_match('/^\+?\d{6,20}$/', $normalized)) ? $normalized : null;
    }
}
