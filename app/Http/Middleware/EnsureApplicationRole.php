<?php

namespace App\Http\Middleware;

use App\Authorization\ResolveApplicationRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requires the authenticated Laravel user to have exactly one valid application role.
 */
class EnsureApplicationRole
{
    public function __construct(private readonly ResolveApplicationRole $roles) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || $this->roles->tryResolve((int) $user->id) === null) {
            abort(403, 'An application role is required.');
        }

        return $next($request);
    }
}
