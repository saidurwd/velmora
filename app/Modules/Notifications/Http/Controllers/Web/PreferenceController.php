<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Http\Controllers\Web;

use App\Modules\Notifications\Actions\SaveMessagePreferences;
use App\Modules\Notifications\Http\Requests\SavePreferencesRequest;
use App\Modules\Notifications\Services\PreferenceReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** A signed-in customer chooses what marketing they want, and their quiet hours. Only ever their own answers: the user comes from the session, never from the address. */
class PreferenceController
{
    public function show(Request $request, PreferenceReader $reader): View
    {
        return view('notifications::account.preferences', ['prefs' => $reader->forUser((int) $request->user('web')?->getAuthIdentifier())]);
    }

    public function update(SavePreferencesRequest $request, SaveMessagePreferences $save): RedirectResponse
    {
        $save->handle(
            (int) $request->user('web')?->getAuthIdentifier(),
            ['email' => $request->boolean('email'), 'sms' => $request->boolean('sms')],   // an unticked box is a "no"
            $request->validated('quiet_from'),
            $request->validated('quiet_to'),
        );

        return back()->with('status', __('notifications::account.saved'));
    }
}
