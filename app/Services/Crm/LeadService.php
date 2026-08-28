<?php

namespace App\Services\Crm;

use App\Enum\Crm\LeadStageEnum;
use App\Models\CrmNote;
use App\Models\Lead;
use App\Models\User;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Platform\TenantProvisioner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator's sales pipeline: prospective laundries, and turning one into a tenant.
 */
class LeadService
{
    public function __construct(
        private readonly TenantProvisioner $provisioner,
        private readonly PlatformSettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Lead
    {
        return Lead::query()->create([
            ...$attributes,
            'stage' => LeadStageEnum::New->value,
            // Where leads come from by default, so an operator entering one by hand does
            // not have to say "phone" every time.
            'source' => $attributes['source'] ?? $this->settings->marketing()['defaultLeadSource'],
        ])->refresh();
    }

    /**
     * Edit a lead, including moving it along the pipeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Lead $lead, array $attributes): Lead
    {
        $stage = isset($attributes['stage'])
            ? LeadStageEnum::from($attributes['stage'])
            : $lead->stage;

        // Stamped on the first entry into won and never rewritten: it is when the deal
        // closed, not when someone last touched the record.
        if ($stage === LeadStageEnum::Won && $lead->won_at === null) {
            $attributes['won_at'] = Carbon::now();
        }

        // A lead that comes back out of lost should not keep the reason it was lost for.
        if ($stage !== LeadStageEnum::Lost) {
            $attributes['lost_reason'] = null;
        }

        $lead->forceFill($attributes)->save();

        return $lead->refresh();
    }

    /**
     * Turn a lead into a real tenant.
     *
     * Provisioning and marking the lead won are one transaction: a lead left open beside
     * the organization it already became is how the same business gets sold to twice.
     *
     * @param  array{admin_name: string, email: string, password: string, plan_id?: int|null}  $owner
     */
    public function convert(Lead $lead, array $owner): Lead
    {
        // The column is the guard, not a status check: a lead could be edited back out of
        // won, and that must not re-open the door to a second organization.
        abort_if($lead->isConverted(), Response::HTTP_CONFLICT, __('api.lead_already_converted'));

        return DB::transaction(function () use ($lead, $owner) {
            $result = $this->provisioner->provision([
                'org_name' => $lead->business_name,
                'admin_name' => $owner['admin_name'],
                'email' => $owner['email'],
                'password' => $owner['password'],
                'phone' => $lead->phone,
                'plan_id' => $owner['plan_id'] ?? null,
            ]);

            $lead->forceFill([
                'converted_organization_id' => $result['organization']->getKey(),
                'stage' => LeadStageEnum::Won->value,
                'won_at' => $lead->won_at ?? Carbon::now(),
                'lost_reason' => null,
            ])->save();

            return $lead->refresh();
        });
    }

    /**
     * Add a note or task to a lead's timeline.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addNote(Lead $lead, User $author, array $attributes): CrmNote
    {
        return $lead->notes()->create([
            ...$attributes,
            'author_id' => $author->getKey(),
        ])->refresh();
    }

    /**
     * The numbers across the top of the pipeline board.
     *
     * @return array{total: int, open: int, won_this_month: int, pipeline_value: float}
     */
    public function summary(): array
    {
        // One row per stage, totalled in PHP. Plain aggregates beat a stack of conditional
        // sums whose bindings have to be kept in the right order to stay correct.
        $byStage = Lead::query()
            ->selectRaw('stage, COUNT(*) as leads, COALESCE(SUM(expected_mrr), 0) as mrr')
            ->groupBy('stage')
            ->get();

        $open = $byStage->filter(static fn ($row): bool => $row->stage->isOpen());

        return [
            'total' => (int) $byStage->sum('leads'),
            'open' => (int) $open->sum('leads'),
            'won_this_month' => Lead::query()
                ->where('stage', LeadStageEnum::Won->value)
                ->where('won_at', '>=', Carbon::now()->startOfMonth())
                ->count(),
            // Only open leads are worth anything: a won one is revenue already, and a lost
            // one is nothing.
            'pipeline_value' => round((float) $open->sum('mrr'), 2),
        ];
    }
}
