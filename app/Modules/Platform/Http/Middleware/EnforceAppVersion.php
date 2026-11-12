<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app-version gate (API_ARCHITECTURE §3): X-App-Platform (android|ios) + X-App-Version below the minimum for that platform
 * is refused with 426 app.upgrade_required, saying what the minimum is and where to get it. No headers = not an app = not gated.
 */
class EnforceAppVersion
{
    public function handle(Request $request, Closure $next): Response
    {
        $platform = strtolower((string) $request->headers->get('X-App-Platform', ''));
        $version = (string) $request->headers->get('X-App-Version', '');
        $minimum = config("api.app.min_supported_version.{$platform}");

        if ($platform !== '' && is_string($minimum) && preg_match('/^\d+(\.\d+){0,3}([-+][\w.-]+)?$/', $version) === 1 && version_compare($version, $minimum, '<')) {
            $store = config("api.app.store_urls.{$platform}");

            return Problem::response(
                ErrorCode::AppUpgradeRequired->status(),
                ErrorCode::AppUpgradeRequired->value,
                __((string) ErrorCode::AppUpgradeRequired->titleKey()),
                null,
                ['min_supported_version' => $minimum, 'store_url' => is_string($store) && $store !== '' ? $store : null],
            );
        }

        return $next($request);
    }
}
