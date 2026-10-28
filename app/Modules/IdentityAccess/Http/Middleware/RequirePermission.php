<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Http\Middleware;

use App\Modules\IdentityAccess\Permissions\PermissionCatalogue;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * `permission:orders.refund` (any of several: `permission:orders.update,orders.cancel`). Always evaluated against the
 * admin guard, never the default one, and deny-by-default: unknown permission names fail closed.
 */
class RequirePermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = Auth::guard('admin')->user();

        if ($user === null) {
            return redirect()->guest(route('admin.login'));
        }

        foreach ($permissions as $permission) {
            if (PermissionCatalogue::exists($permission) && $user->can($permission)) {
                return $next($request);
            }
        }

        abort(403);
    }
}
