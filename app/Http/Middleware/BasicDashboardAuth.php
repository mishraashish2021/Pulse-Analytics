<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BasicDashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = (string) config('analytics.dashboard.user');
        $password = config('analytics.dashboard.password');

        // Fail closed: no password configured means the dashboard is off.
        if (! $password) {
            abort(503, 'Dashboard is disabled: set ANALYTICS_DASHBOARD_PASSWORD.');
        }

        $validUser = hash_equals($user, (string) $request->getUser());
        $validPassword = hash_equals((string) $password, (string) $request->getPassword());

        if (! ($validUser && $validPassword)) {
            return response('Unauthorized', 401, ['WWW-Authenticate' => 'Basic realm="Pulse Analytics"']);
        }

        return $next($request);
    }
}
