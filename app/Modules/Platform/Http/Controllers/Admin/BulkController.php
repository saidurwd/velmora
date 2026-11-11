<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Actions\RunBulkAction;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\DTOs\BulkResult;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Http\Requests\BulkActionRequest;
use App\Modules\Platform\Support\BulkActions;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Several rows at once: first a page that lists the chosen rows and asks anything the action needs, then the run, then the answer row by row. The route only needs a signed-in
 * staff member; WHICH action a person may run is checked here against the action's own permissions.
 */
class BulkController
{
    public function confirm(BulkActionRequest $request, BulkActions $actions): View
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);
        $action = $request->bulk();
        abort_unless($actions->allowed($user, $action), 403);

        $ids = $request->ids();
        $labels = $action->describe($ids);

        return view('platform::admin.bulk.confirm', [
            'action' => $action, 'rows' => array_map(fn (string $id): array => ['id' => $id, 'label' => $labels[$id] ?? null], $ids), 'fields' => $action->fields($user), 'max' => BulkActions::MAX_ROWS,
        ]);
    }

    public function run(BulkActionRequest $request, BulkActions $actions, RunBulkAction $run): RedirectResponse
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);
        $action = $request->bulk();
        abort_unless($actions->allowed($user, $action), 403);

        $result = $run->handle(new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), (string) $user->public_id, (string) $user->name), $user, $action, $request->ids(), $request->params());

        return redirect()->route('admin.bulk.result')->with('bulk_result', ['action' => $result->action, 'items' => $result->items]);
    }

    public function result(Request $request): View|RedirectResponse
    {
        /** @var array{action: string, items: list<array{id: string, label: string, ok: bool, message: string|null}>}|null $saved */
        $saved = $request->session()->get('bulk_result');
        if ($saved === null) {
            return redirect()->route('admin.dashboard');
        }
        $request->session()->reflash();

        return view('platform::admin.bulk.result', ['result' => new BulkResult($saved['action'], $saved['items']), 'action' => app(BulkActions::class)->find($saved['action'])]);
    }
}
