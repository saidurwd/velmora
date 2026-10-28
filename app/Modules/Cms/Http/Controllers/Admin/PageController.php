<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PageController
{
    public function index(): View
    {
        return view('cms::admin.pages.index', ['pages' => Page::query()->with('translations')->latest('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('cms::admin.pages.form', ['page' => null]);
    }

    public function store(Request $request, SavePage $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(Page $page): View
    {
        return view('cms::admin.pages.form', ['page' => $page->load('translations')]);
    }

    public function update(Request $request, Page $page, SavePage $save): RedirectResponse
    {
        return $this->persist($request, $save, $page);
    }

    public function transition(Request $request, Page $page, TransitionPage $transition): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:publish,schedule,unpublish'], 'publish_at' => ['required_if:action,schedule', 'nullable', 'date']]);

        try {
            match ($data['action']) {
                'publish' => $transition->publish($this->actor($request), $page),
                'schedule' => $transition->publish($this->actor($request), $page, CarbonImmutable::parse((string) $data['publish_at'], (string) config('app.display_timezone'))->utc()),
                'unpublish' => $transition->unpublish($this->actor($request), $page),
                default => abort(422),
            };
        } catch (CmsException $e) {
            return back()->withErrors(['transition' => __('cms::admin.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('cms::admin.saved'));
    }

    public function destroy(Page $page): RedirectResponse
    {
        abort_if($page->status === 'published', 422, __('cms::admin.unpublish_first'));
        $page->delete();

        return redirect()->route('admin.cms.pages.index')->with('status', __('cms::admin.saved'));
    }

    private function persist(Request $request, SavePage $save, ?Page $page): RedirectResponse
    {
        $default = (string) config('app.default_locale');
        $rules = ["translations.{$default}.title" => ['required', 'string', 'max:200']];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["translations.{$locale}.title"] ??= ['nullable', 'string', 'max:200'];
            $rules["translations.{$locale}.slug"] = ['nullable', 'string', 'max:120'];
            $rules["translations.{$locale}.body"] = ['nullable', 'string', 'max:100000'];
            $rules["translations.{$locale}.seo_title"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.seo_description"] = ['nullable', 'string', 'max:320'];
        }
        $data = $request->validate($rules);

        try {
            $saved = $save->handle($this->actor($request), $page, (array) $data['translations']);
        } catch (CmsException $e) {
            return back()->withInput()->withErrors(['translations' => __('cms::admin.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.cms.pages.edit', $saved)->with('status', __('cms::admin.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
