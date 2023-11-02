<?php

namespace App\Providers;

// use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Passport::tokensExpireIn(now()->addDays(15));
        Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(6));


        Gate::before(function ($user, $ability) {
            // Check the ability and apply your authorization logic
            if (in_array($ability, ['backup', 'superadmin', 'manage_modules'])) {
                $administrator_list = config('constants.administrator_usernames');

                if (in_array($user->username, explode(',', $administrator_list))) {
                    return true;
                }
            }else {
                $permission = DB::table('permissions as p')
                    ->join('role_has_permissions as rhp', 'rhp.permission_id', '=', 'p.id')
                    ->join('roles as r', 'rhp.role_id', '=', 'r.id')
                    ->join('model_has_roles as mhr', 'mhr.role_id', '=', 'r.id')
                    ->where('mhr.model_id', $user->id)
                    ->select(
                        'p.name as permissions',
                        'r.name as role_name'
                    )
                    ->get()->toArray();

                if (in_array('Admin#' . $user->business_id, array_column($permission, 'role_name'))) {
                    return true;
                } elseif (in_array($ability, array_column($permission, 'permissions'))) {
                    return true;
                }

            }
        });
    }
}
