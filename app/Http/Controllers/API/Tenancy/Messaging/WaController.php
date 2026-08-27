<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\Messaging\WaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

/**
 * The organization's WhatsApp screen. Reads need the messaging feature; writes add
 * requireManager. OTP bodies are hidden from the message log.
 */
class WaController extends TenantController
{
    private const FEATURE = 'messaging';

    private const HIDDEN_OTP = '•••• رمز تحقق (مخفي)';

    public function __construct(private readonly WaService $wa)
    {
        parent::__construct();
    }

    public function overview(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $organizationId = $this->organizationId();

        return successResponse([
            'usage' => [
                'org_used' => $this->wa->monthUsed($organizationId, null),
            ],
            'stats' => [
                'by_status' => $this->countBy('status'),
                'by_category' => $this->countBy('category'),
                'by_event' => $this->countBy('event_key'),
            ],
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $limit = min((int) $request->input('limit', 100), 200);

        $messages = WaMessage::query()
            ->where('organization_id', $this->organizationId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->input('category')))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'to_phone', 'category', 'event_key', 'body', 'status', 'sender_mode', 'sent_at', 'created_at']);

        $messages->transform(function (WaMessage $m) {
            // Never surface an OTP body to staff.
            if ($m->category === WaCategoryEnum::Authentication || $m->event_key === WaEventEnum::Otp->value) {
                $m->body = self::HIDDEN_OTP;
            }

            return $m;
        });

        return successResponse($messages);
    }

    public function templates(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        return successResponse([
            'org' => WaTemplate::query()->where('organization_id', $this->organizationId())->latest('id')->get(),
            'platform_defaults' => WaTemplate::query()->whereNull('organization_id')->get(),
            'events' => WaEventEnum::values(),
            'categories' => WaCategoryEnum::values(),
        ]);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $template = WaTemplate::query()->create([
            ...$this->validateTemplate($request),
            'organization_id' => $this->organizationId(),
            'created_by_id' => $this->staff()->getKey(),
        ]);

        return successResponse($template, __('api.created_success'), 201);
    }

    public function updateTemplate(Request $request, WaTemplate $template): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($template);

        $template->update($this->validateTemplate($request, updating: true));

        return successResponse($template->refresh(), __('api.updated_success'));
    }

    public function deleteTemplate(WaTemplate $template): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($template);

        $template->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    /**
     * Send a test message rendered with dummy variables.
     */
    public function test(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        $message = $this->wa->queue([
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
            'to_phone' => $data['phone'],
            'category' => WaEventEnum::Test->category(),
            'event_key' => WaEventEnum::Test->value,
            'body' => $this->wa->render(
                $this->wa->resolveTemplate($this->organizationId(), WaEventEnum::Test),
                ['org' => $this->organization()->name, 'orderNo' => 'TEST-1', 'code' => '0000', 'name' => 'تجربة'],
            ),
        ]);

        return successResponse(['status' => $message->status->value], __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<int, array{key: string, count: int}>
     */
    private function countBy(string $column): array
    {
        return WaMessage::query()
            ->where('organization_id', $this->organizationId())
            ->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw("{$column} as k, COUNT(*) as c")
            ->groupBy($column)
            ->get()
            ->map(fn ($r) => ['key' => $r->k instanceof \BackedEnum ? $r->k->value : $r->k, 'count' => (int) $r->c])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validateTemplate(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'category' => [$required, new Enum(WaCategoryEnum::class)],
            'event_key' => ['nullable', new Enum(WaEventEnum::class)],
            'body' => [$required, 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}
