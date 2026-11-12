<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Contracts;

use Illuminate\Http\Request;

/** Which app sign-in (its "session id") the current request belongs to, for modules that attach things to a sign-in (push devices). */
interface ApiSessionResolver
{
    /** The id of the sign-in whose access token made this request, or null when the request is not from an app sign-in. */
    public function currentFamily(Request $request): ?string;
}
