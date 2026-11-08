<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\AssignPolicyKind;
use App\Modules\Cms\Enums\PolicyKind;
use App\Modules\Cms\Exceptions\PolicyException;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PolicyController
{
    public function index(Request $request): View
    {
        $pages = Page::query()->whereIn('kind', PolicyKind::values())->with('draftVersion.translations')->get()->keyBy('kind');
        $releases = DB::table('cms_policy_releases')->orderByDesc('id')->get()->groupBy('kind');
        $versions = DB::table('cms_page_versions')->whereIn('id', $releases->flatten()->pluck('version_id')->unique()->all())->pluck('number', 'id');

        $counts = collect();
        if ($request->user('admin')?->can('policies.acceptance.view')) {
            $counts = DB::table('policy_acceptances')->whereIn('release_id', $releases->flatten()->pluck('id')->all())->groupBy('release_id', 'channel')
                ->selectRaw('release_id, channel, COUNT(*) as total')->get()->groupBy('release_id');
        }

        return view('cms::admin.policies.index', [
            'kinds' => PolicyKind::cases(), 'pages' => $pages, 'releases' => $releases, 'versions' => $versions, 'counts' => $counts,
            'candidates' => Page::query()->whereNull('kind')->with('draftVersion.translations')->orderByDesc('id')->limit(200)->get(),
        ]);
    }

    public function assign(Request $request, AssignPolicyKind $assign, string $kind): RedirectResponse
    {
        $enum = PolicyKind::tryFrom($kind) ?? abort(404);
        $data = $request->validate(['page' => ['nullable', 'string', 'size:26']]);

        $target = isset($data['page']) && $data['page'] !== '' ? Page::query()->where('public_id', $data['page'])->firstOrFail() : null;
        $current = Page::query()->where('kind', $enum->value)->first();
        $user = $request->user('admin');
        abort_if($user === null, 403);
        $actor = new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);

        try {
            if ($target === null) {
                $current === null || $assign->handle($actor, $current, null);
            } else {
                $assign->handle($actor, $target, $enum);
            }
        } catch (PolicyException $e) {
            return back()->withErrors(['policies' => __('cms::policies.errors.'.$e->reason)]);
        }

        return back()->with('status', __('cms::admin.saved'));
    }
}
