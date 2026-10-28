<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Web;

use App\Modules\Customers\Contracts\CustomerProfiles;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class AccountController
{
    public function show(Request $request, CustomerProfiles $profiles): View
    {
        $user = $request->user('web');
        $profile = $profiles->findByUserId((int) $user?->getAuthIdentifier()); // may lag a moment behind registration (outbox)

        return view('customers::account.show', [
            'displayName' => $profile?->name ?: ($user?->name ?: ($profile?->phone ?: $user?->phone ?: $user?->email)),
        ]);
    }
}
