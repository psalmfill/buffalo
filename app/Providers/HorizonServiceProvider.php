<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();
    }

    /**
     * Horizon's default also lets anyone in when APP_ENV=local. BUFFALO
     * uses the gate alone, in every environment.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    /**
     * Only users with the system.horizon permission (super admins). Blocked
     * accounts are denied by the global Gate::before hook.
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (User $user): bool => $user->checkPermissionTo(Permission::SystemHorizon->value));
    }
}
