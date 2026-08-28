<?php

namespace App\Services\Crm;

use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Models\CrmNote;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The operator's follow-up desk: which tenants need attention, and the notes kept against
 * them.
 */
class CrmService
{
    /**
     * How close a trial has to be to its end before it wants chasing.
     */
    private const TRIAL_ENDING_DAYS = 7;

    /**
     * Tenants worth a call, each with the reason it is on the list.
     *
     * Built as one query per reason and merged, rather than one query with a stack of
     * ORs: a tenant can qualify twice (suspended *and* past due), and the operator needs
     * to see both reasons rather than whichever the query happened to match first.
     *
     * @return array<int, array{organization: Organization, reasons: array<int, string>}>
     */
    public function attentionList(): array
    {
        $now = Carbon::now();

        $reasons = [
            'past_due' => $this->whereSubscription(
                fn (Builder $q) => $q->where('status', PlatformSubscriptionStatusEnum::PastDue->value),
            ),

            // Expired is not a status but a lapsed period under a status that should still
            // be paying — which is exactly the account nobody notices has stopped.
            'expired' => $this->whereSubscription(fn (Builder $q) => $q
                ->whereIn('status', PlatformSubscriptionStatusEnum::writableValues())
                ->whereNotNull('current_period_end')
                ->where('current_period_end', '<', $now)),

            // Still paid up, but has already asked not to renew.
            'canceling' => $this->whereSubscription(fn (Builder $q) => $q
                ->where('cancel_at_period_end', true)
                ->whereNull('canceled_at')),

            'trial_ending' => $this->whereSubscription(fn (Builder $q) => $q
                ->where('status', PlatformSubscriptionStatusEnum::Trial->value)
                ->whereNotNull('current_period_end')
                ->whereBetween('current_period_end', [$now, $now->copy()->addDays(self::TRIAL_ENDING_DAYS)])),

            'suspended' => Organization::query()->tenantsOnly()->where('is_suspended', true),
        ];

        $flagged = [];

        foreach ($reasons as $reason => $query) {
            foreach ($query->with('platformSubscription.plan')->get() as $organization) {
                $id = $organization->getKey();

                $flagged[$id] ??= ['organization' => $organization, 'reasons' => []];
                $flagged[$id]['reasons'][] = $reason;
            }
        }

        return array_values($flagged);
    }

    /**
     * Record a note or task against a lead or a tenant.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addNote(User $author, array $attributes): CrmNote
    {
        return CrmNote::query()->create([
            ...$attributes,
            'author_id' => $author->getKey(),
        ])->refresh();
    }

    /**
     * Mark a task done.
     *
     * Only a task can be: marking a record of a phone call "done" is meaningless, since
     * it already happened.
     */
    public function complete(CrmNote $note): CrmNote
    {
        abort_unless(
            $note->kind->isCompletable(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.crm_note_not_a_task'),
        );

        // First completion wins. Re-marking would rewrite when the work was actually
        // finished, which is the one thing the timestamp is for.
        if ($note->done_at === null) {
            $note->forceFill(['done_at' => Carbon::now()])->save();
        }

        return $note->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Tenants whose subscription matches the given condition.
     */
    private function whereSubscription(callable $condition): Builder
    {
        return Organization::query()
            ->tenantsOnly()
            ->whereHas('platformSubscription', $condition);
    }
}
