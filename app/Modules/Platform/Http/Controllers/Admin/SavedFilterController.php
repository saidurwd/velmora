<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Exceptions\SavedFilterException;
use App\Modules\Platform\Support\SavedFilters;
use App\Modules\Platform\Support\SavedFilterScreens;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Personal saved filters. A person may save filters only for a list they may open, and may only see and remove their own. */
class SavedFilterController
{
    public function store(Request $request, SavedFilters $filters, SavedFilterScreens $screens): RedirectResponse
    {
        $data = $request->validate(['screen' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:60'], 'f' => ['nullable', 'array']]);
        abort_unless($screens->has($data['screen']), 404);
        $user = $request->user('admin');
        abort_unless($user?->can($screens->permission($data['screen'])) === true, 403);

        try {
            $filters->save((int) $user->getAuthIdentifier(), $data['screen'], $data['name'], (array) ($data['f'] ?? []));
        } catch (SavedFilterException $e) {
            return redirect()->route($screens->route($data['screen']))->withErrors(['saved_filter' => __('platform::productivity.saved.errors.'.$e->reason)]);
        }

        return redirect()->route($screens->route($data['screen']), $filters->clean($data['screen'], (array) ($data['f'] ?? [])))->with('status', __('platform::productivity.saved.saved'));
    }

    public function destroy(Request $request, SavedFilters $filters, string $filter): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);
        abort_unless($filters->delete((int) $user->getAuthIdentifier(), $filter), 404);

        return back()->with('status', __('platform::productivity.saved.deleted'));
    }
}
