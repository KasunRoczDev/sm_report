<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class TenantConfig
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! empty($request->header('X-Tenant'))) {

            Session::flush(); // Clear the session data

            Config::set('database.connections.tenants.database', $request->header('X-Tenant'));
            DB::connection('tenants')->reconnect();
            Config::set('database.default', 'tenants');
        }

        return $next($request);
    }
}
