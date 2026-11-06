<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\SaveFaqEntry;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Cms\Services\FaqReader;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The help centre's questions. Looking = cms.view, writing = cms.edit, and showing or hiding one on the help page needs cms.publish (a new one starts hidden). */
class FaqController
{
    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }

    public function index(FaqReader $faq): View
    {
        return view('cms::admin.faq.index', ['entries' => $faq->all(app()->getLocale())]);
    }

    public function create(): View
    {
        return view('cms::admin.faq.form', ['entry' => null, 'locales' => (array) config('app.supported_locales'), 'categories' => SaveFaqEntry::CATEGORIES]);
    }

    public function edit(FaqReader $faq, string $entry): View
    {
        $found = $faq->one($entry);
        abort_if($found === null, 404);

        return view('cms::admin.faq.form', ['entry' => $found, 'locales' => (array) config('app.supported_locales'), 'categories' => SaveFaqEntry::CATEGORIES]);
    }

    public function save(Request $request, SaveFaqEntry $save, ?string $entry = null): RedirectResponse
    {
        $data = $request->validate([
            'category' => ['required', 'string', 'max:20'], 'sort' => ['nullable', 'integer', 'min:0', 'max:65535'], 'published' => ['sometimes', 'nullable', 'boolean'],
            'texts' => ['required', 'array'], 'texts.*.question' => ['nullable', 'string', 'max:200'], 'texts.*.answer' => ['nullable', 'string', 'max:4000'],
        ]);
        $canPublish = $request->user('admin')?->can('cms.publish') === true;
        try {
            $save->handle($this->actor($request), $entry, $data['category'], (int) ($data['sort'] ?? 0), $request->has('published_present') ? $request->boolean('published') : null, $canPublish, $data['texts']);
        } catch (CmsException $e) {
            abort_if($e->reason === 'faq_not_found', 404);

            return back()->withInput()->withErrors(['faq' => __('cms::admin.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.cms.faq.index')->with('status', __('cms::faq.saved'));
    }

    public function destroy(Request $request, SaveFaqEntry $save, string $entry): RedirectResponse
    {
        try {
            $save->delete($this->actor($request), $entry);
        } catch (CmsException) {
            abort(404);
        }

        return redirect()->route('admin.cms.faq.index')->with('status', __('cms::faq.deleted'));
    }
}
