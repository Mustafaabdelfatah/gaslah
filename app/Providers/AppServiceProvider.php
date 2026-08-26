<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Policies\User\RolePolicy;
use App\Policies\User\UserPolicy;
use App\Services\Messaging\Providers\LogProvider;
use App\Services\Messaging\Providers\MessagingProvider;
use App\Services\Messaging\Providers\WhatsAppProvider;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

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
    }
}
