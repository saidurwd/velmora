<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\SavePage;
use App\Modules\Cms\Actions\TransitionPage;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Page;
use App\Modules\Cms\Models\PageVersion;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\StorefrontCache;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PageController
{
    public function index(): View
    {
        return view('cms::admin.pages.index', ['pages' => Page::query()->with(['draftVersion.translations', 'liveVersion', 'scheduledVersion'])->latest('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('cms::admin.pages.form', ['page' => null, 'draft' => null, 'versions' => collect()]);
    }

    public function store(Request $request, SavePage $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(Page $page): View
    {
        $page->load(['draftVersion.translations', 'liveVersion', 'scheduledVersion']);

        return view('cms::admin.pages.form', ['page' => $page, 'draft' => $page->draftVersion, 'versions' => $page->versions()->limit(50)->get()]);
    }

    public function update(Request $request, Page $page, SavePage $save): RedirectResponse
    {
        return $this->persist($request, $save, $page);
    }

    public function transition(Request $request, Page $page, TransitionPage $transition): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:publish,schedule,cancel_schedule,unpublish'],
            'version' => ['required_if:action,publish,schedule', 'nullable', 'integer', 'min:1'],
            'publish_at' => ['required_if:action,schedule', 'nullable', 'date'],
            'acceptance_required' => ['nullable', 'in:0,1'],
        ]);
        $actor = $this->actor($request);
        // only someone who manages policies may call a release a minor edit; everyone else's release asks customers to accept again
        $required = $page->kind === null || ! $request->user('admin')?->can('cms.policies.manage') || (string) ($data['acceptance_required'] ?? '1') === '1';

        try {
            match ($data['action']) {
                'publish' => $transition->publish($actor, $page, (int) $data['version'], $required),
                'schedule' => $transition->schedule($actor, $page, (int) $data['version'], CarbonImmutable::parse((string) $data['publish_at'], (string) config('app.display_timezone'))->utc(), $required),
                'cancel_schedule' => $transition->cancelSchedule($actor, $page),
                'unpublish' => $transition->unpublish($actor, $page),
                default => abort(422),
            };
        } catch (CmsException $e) {
            return back()->withErrors(['transition' => __('cms::admin.errors.'.$e->reason, $e->params)]);
        }

        return back()->with('status', __('cms::admin.saved'));
    }

    /** Staff only: any saved version, published or not, shown as the public page would look. Never cached or indexed. */
    public function preview(Request $request, Page $page, int $number): Response
    {
        $version = PageVersion::query()->with('translations')->where('page_id', $page->id)->where('number', $number)->firstOrFail();
        $supported = (array) config('app.supported_locales');
        $locale = in_array($request->query('locale'), $supported, true) ? (string) $request->query('locale') : (string) config('app.default_locale');
        $translation = $version->translations->firstWhere('locale', $locale) ?? $version->translations->firstWhere('locale', (string) config('app.default_locale'));
        abort_if($translation === null, 404);
        app()->setLocale($locale);

        return response()->view('cms::page', ['translation' => $translation, 'preview' => ['number' => $version->number, 'page' => $page, 'locale' => $locale, 'locales' => $version->translations->pluck('locale')->all()]])
            ->header('Cache-Control', 'no-store, private')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function destroy(Page $page): RedirectResponse
    {
        abort_if($page->status === 'published', 422, __('cms::admin.unpublish_first'));
        abort_if($page->kind !== null, 422, __('cms::policies.errors.kind_locked'));
        $page->delete();
        app(StorefrontCache::class)->bump();

        return redirect()->route('admin.cms.pages.index')->with('status', __('cms::admin.saved'));
    }

    private function persist(Request $request, SavePage $save, ?Page $page): RedirectResponse
    {
        $default = (string) config('app.default_locale');
        $rules = ["translations.{$default}.title" => ['required', 'string', 'max:200']];
        $page === null || $rules['base_version'] = ['required', 'integer', 'min:0'];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["translations.{$locale}.title"] ??= ['nullable', 'string', 'max:200'];
            $rules["translations.{$locale}.slug"] = ['nullable', 'string', 'max:120'];
            $rules["translations.{$locale}.body"] = ['nullable', 'string', 'max:100000'];
            $rules["translations.{$locale}.seo_title"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.seo_description"] = ['nullable', 'string', 'max:320'];
        }
        $data = $request->validate($rules);

        try {
            $saved = $save->handle($this->actor($request), $page, (array) $data['translations'], $page === null ? null : (int) $data['base_version']);
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
