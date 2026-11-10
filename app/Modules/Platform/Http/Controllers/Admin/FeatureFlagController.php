<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\SetFeatureFlag;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Exceptions\SettingsException;
use App\Modules\Platform\Support\FeatureFlags;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Feature switches. Looking = settings.view, changing = features.manage. */
class FeatureFlagController
{
    public function index(FeatureFlags $flags): View
    {
        $rows = [];
        foreach (FeatureFlags::names() as $name) {
            $rows[] = ['name' => $name, 'on' => $flags->enabled($name), 'byStaff' => $flags->isSetByStaff($name), 'default' => $flags->environmentDefault($name)];
        }

        return view('platform::admin.settings.features', ['flags' => $rows]);
    }

    public function update(Request $request, SetFeatureFlag $set, string $flag): RedirectResponse
    {
        $data = $request->validate(['value' => ['required', 'in:on,off,default']]);
        $user = $request->user('admin');
        abort_if($user === null, 403);

        try {
            $set->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name), $flag, match ($data['value']) {
                'on' => true, 'off' => false, default => null,
            });
        } catch (SettingsException) {
            abort(404);
        }

        return redirect()->route('admin.features.index')->with('status', __('platform::settings.saved'));
    }
}
