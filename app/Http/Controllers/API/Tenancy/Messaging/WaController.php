<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Messaging\SendTestMessageRequest;
use App\Http\Requests\Messaging\WaTemplateRequest;
use App\Models\Branch;
use App\Models\WaMessage;
use App\Models\WaTemplate;
use App\Services\Messaging\WaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's WhatsApp screen. Reads need the messaging feature; writes add
 * requireManager. OTP bodies are hidden from the message log.
 */
class WaController extends TenantController
{
    private const HIDDEN_OTP = '•••• رمز تحقق (مخفي)';

    public function __construct(private readonly WaService $wa)
    {
        parent::__construct();
    }

    public function overview(): JsonResponse
    {
        $this->staff();

        $organizationId = $this->organizationId();

        $branches = Branch::query()
            ->where('organization_id', $organizationId)
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (Branch $branch) => ['id' => $branch->id, 'name' => $branch->name])
            ->all();

        return successResponse([
            'usage' => $this->wa->quotaSnapshot($organizationId, $branches),
            'stats' => [
                'by_status' => $this->wa->monthlyCountsBy($organizationId, 'status'),
                'by_category' => $this->wa->monthlyCountsBy($organizationId, 'category'),
                'by_event' => $this->wa->monthlyCountsBy($organizationId, 'event_key'),
                'trend' => $this->wa->monthlyTrend($organizationId),
            ],
        ]);
    }

    public function messages(Request $request): JsonResponse
    {
        $this->staff();

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

        return successResponse([
            'org' => WaTemplate::query()->where('organization_id', $this->organizationId())->latest('id')->get(),
            'platform_defaults' => WaTemplate::query()->whereNull('organization_id')->get(),
            'events' => WaEventEnum::values(),
            'categories' => WaCategoryEnum::values(),
        ]);
    }

    public function storeTemplate(WaTemplateRequest $request): JsonResponse
    {

        $template = WaTemplate::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'created_by_id' => $this->staff()->getKey(),
        ]);

        return successResponse($template, __('api.created_success'), 201);
    }

    public function updateTemplate(WaTemplateRequest $request, WaTemplate $template): JsonResponse
    {
        $this->assertOwned($template);

        $template->update($request->validated());

        return successResponse($template->refresh(), __('api.updated_success'));
    }

    public function deleteTemplate(WaTemplate $template): JsonResponse
    {
        $this->assertOwned($template);

        $template->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    /**
     * Send a test message rendered with dummy variables.
     */
    public function test(SendTestMessageRequest $request): JsonResponse
    {

        $message = $this->wa->queue([
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
            'to_phone' => $request->phone(),
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

}
