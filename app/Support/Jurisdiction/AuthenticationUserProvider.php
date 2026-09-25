<?php

namespace App\Support\Jurisdiction;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * User provider for authentication (config/auth.php, driver "buffalo").
 *
 * SANCTIONED SCOPE BYPASS. Authentication has to find a user by id,
 * credentials or remember token before any jurisdiction exists: the request
 * is still in `none` mode, in which the scoped User model returns no rows.
 * The provider only ever resolves the account that is authenticating
 * itself, and never returns data to anyone else. Whether the account may
 * log in (active, not locked) is decided by the login flow (Phase 1C).
 */
final class AuthenticationUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->withoutGlobalScope(JurisdictionScope::class);
    }
}
