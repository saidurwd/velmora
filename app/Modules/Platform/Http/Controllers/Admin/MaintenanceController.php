<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\MaintenanceSwitch;
use App\Modules\Platform\Contracts\StaffReauthentication;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\SettingsException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Maintenance mode. Looking = settings.view, switching = settings.manage and the staff member's own password again. */
class MaintenanceController
{
    public function show(): View
    {
        return view('platform::admin.settings.maintenance', ['down' => app()->isDownForMaintenance()]);
    }

    public function enable(Request $request, MaintenanceSwitch $switch, StaffReauthentication $reauth): RedirectResponse
    {
        return $this->run($request, $reauth, function (Actor $actor) use ($switch): RedirectResponse {
            $secret = $switch->enable($actor);

            return redirect()->route('admin.maintenance.show')->with('bypass_url', url('/'.$secret))->with('status', __('platform::settings.maintenance.enabled'));
        });
    }

    public function disable(Request $request, MaintenanceSwitch $switch, StaffReauthentication $reauth): RedirectResponse
    {
        return $this->run($request, $reauth, function (Actor $actor) use ($switch): RedirectResponse {
            $switch->disable($actor);

            return redirect()->route('admin.maintenance.show')->with('status', __('platform::settings.maintenance.disabled'));
        });
    }

    /** @param  callable(Actor): RedirectResponse  $then */
    private function run(Request $request, StaffReauthentication $reauth, callable $then): RedirectResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:200']]);
        $user = $request->user('admin');
        abort_if($user === null, 403);

        if (! $reauth->confirmPassword((int) $user->getAuthIdentifier(), $data['password'])) {
            return back()->withErrors(['password' => __('platform::settings.errors.password')]);
        }

        try {
            return $then(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name));
        } catch (SettingsException $e) {
            return back()->withErrors(['maintenance' => __('platform::settings.errors.'.$e->reason)]);
        }
    }
}
