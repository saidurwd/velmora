<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\SaveBanner;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Models\Banner;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

class BannerController
{
    public function index(): View
    {
        return view('cms::admin.banners.index', ['banners' => Banner::query()->with('translations')->orderBy('placement')->orderBy('sort_order')->orderBy('id')->paginate(30)]);
    }

    public function create(): View
    {
        return view('cms::admin.banners.form', ['banner' => null]);
    }

    public function store(Request $request, SaveBanner $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(Banner $banner): View
    {
        return view('cms::admin.banners.form', ['banner' => $banner->load('translations')]);
    }

    public function update(Request $request, Banner $banner, SaveBanner $save): RedirectResponse
    {
        return $this->persist($request, $save, $banner);
    }

    public function destroy(Banner $banner): RedirectResponse
    {
        $banner->delete();

        return redirect()->route('admin.cms.banners.index')->with('status', __('cms::admin.saved'));
    }

    private function persist(Request $request, SaveBanner $save, ?Banner $banner): RedirectResponse
    {
        $rules = [
            'placement' => ['required', 'in:'.implode(',', SaveBanner::PLACEMENTS)],
            'link_url' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_local' => ['nullable', 'date'],
            'ends_local' => ['nullable', 'date'],
        ];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["translations.{$locale}.title"] = ['nullable', 'string', 'max:160'];
            $rules["translations.{$locale}.subtitle"] = ['nullable', 'string', 'max:255'];
            $rules["translations.{$locale}.cta_label"] = ['nullable', 'string', 'max:60'];
            $rules["translations.{$locale}.alt"] = ['nullable', 'string', 'max:200'];
            $rules["images.{$locale}"] = ['nullable', 'file', 'max:8192'];
        }
        $data = $request->validate($rules);

        $translations = [];
        foreach ((array) config('app.supported_locales') as $locale) {
            $t = (array) ($data['translations'][$locale] ?? []);
            $file = $request->file("images.{$locale}");
            $t['image'] = $file instanceof UploadedFile ? (string) file_get_contents((string) $file->getRealPath()) : null;

            if (array_filter($t, fn ($v) => $v !== null && $v !== '') !== [] || $banner?->translations->contains('locale', $locale)) {
                $translations[(string) $locale] = $t;
            }
        }

        try {
            $save->handle($this->actor($request), $banner, [
                'placement' => $data['placement'], 'link_url' => $data['link_url'] ?? null, 'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => $request->boolean('is_active'), 'starts_local' => $data['starts_local'] ?? null, 'ends_local' => $data['ends_local'] ?? null,
            ], $translations);
        } catch (CmsException $e) {
            return back()->withInput()->withErrors(['banner' => __('cms::admin.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.cms.banners.index')->with('status', __('cms::admin.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
