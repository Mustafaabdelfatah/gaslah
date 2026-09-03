<?php

namespace App\Providers;

use App\Contracts\ZatcaCsrGenerator;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Policies\User\RolePolicy;
use App\Policies\User\UserPolicy;
use App\Services\Messaging\Providers\LogProvider;
use App\Services\Messaging\Providers\MessagingProvider;
use App\Services\Messaging\Providers\WhatsAppProvider;
use App\Services\Platform\PlatformSettingsService;
use App\Services\Tenancy\TenantContext;
use App\Services\Zatca\OpenSslZatcaCsrGenerator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Scoped: the resolved tenant belongs to one request and must not leak into
        // the next one on a long-running worker.
        $this->app->scoped(TenantContext::class);

        // Scoped for the same reason, and because it memoises: the platform's settings
        // live in a table, and a listing that asks for the same group once per row would
        // turn one setting into an N+1.
        $this->app->scoped(PlatformSettingsService::class);

        // The generator is swappable so onboarding tests never execute a host binary,
        // while production still gets the ZATCA-specific OpenSSL extensions.
        $this->app->bind(ZatcaCsrGenerator::class, OpenSslZatcaCsrGenerator::class);

        // The platform messaging transport: the real WhatsApp provider when platform
        // credentials are set, otherwise the log stub. Custom per-org providers are
        // built directly by WaService.
        $this->app->bind(MessagingProvider::class, function () {
            $token = config('services.whatsapp.token');
            $phoneId = config('services.whatsapp.phone_id');

            if (! empty($token) && ! empty($phoneId)) {
                return new WhatsAppProvider($token, $phoneId, config('services.whatsapp.base_url', 'https://graph.facebook.com/v19.0'));
            }

            return new LogProvider;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Policies
        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(User::class, UserPolicy::class);

        $this->stampTenantOnActivity();
    }

    /**
     * Stamp the owning tenant on every activity row as it is written.
     *
     * Done centrally rather than per model on purpose: the audit trail's isolation must
     * hold for every logged model, including ones added later by someone who never reads
     * this file. The tenant is taken from the record itself where it has one, and
     * otherwise from the acting user's context; platform-level activity stays null.
     */
    private function stampTenantOnActivity(): void
    {
        Activity::saving(static function (Activity $activity): void {
            if ($activity->organization_id !== null) {
                return;
            }

            $subject = $activity->subject;

            $activity->organization_id = $subject?->getAttribute('organization_id')
                ?? app(TenantContext::class)->organizationId();
        });
    }
}
