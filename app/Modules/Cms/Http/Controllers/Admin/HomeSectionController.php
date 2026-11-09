<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Catalog\Contracts\HomeCollections;
use App\Modules\Cms\Actions\DeleteHomeSection;
use App\Modules\Cms\Actions\MoveHomeSection;
use App\Modules\Cms\Actions\SaveHomeSection;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\Contracts\Clock;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use App\Modules\Platform\Support\HtmlSanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The home page's blocks. Looking = cms.view, writing and ordering = cms.edit, showing or hiding one = cms.publish (a new one starts hidden). */
class HomeSectionController
{
    public function index(Clock $clock, HtmlSanitizer $html): View
    {
        $default = (string) config('app.default_locale');
        $now = $clock->now();
        $rows = DB::table('cms_home_sections as s')->leftJoin('cms_home_section_translations as t', fn ($j) => $j->on('t.section_id', '=', 's.id')->where('t.locale', '=', $default))
            ->orderBy('s.sort_order')->orderBy('s.id')->get(['s.id', 's.public_id', 's.type', 's.is_active', 's.starts_at', 's.ends_at', 's.collection_ref', 't.title', 't.body']);

        $sections = $rows->map(function ($r) use ($now, $html): array {
            $starts = $r->starts_at === null ? null : CarbonImmutable::parse((string) $r->starts_at, 'UTC');
            $ends = $r->ends_at === null ? null : CarbonImmutable::parse((string) $r->ends_at, 'UTC');
            $state = match (true) {
                ! $r->is_active => 'hidden',
                $starts !== null && $starts->greaterThan($now) => 'scheduled',
                $ends !== null && $ends->lessThanOrEqualTo($now) => 'ended',
                default => 'live',
            };

            return ['row' => $r, 'state' => $state, 'starts' => $starts, 'ends' => $ends, 'body' => $r->type === 'text' ? $html->clean((string) $r->body) : null];
        })->all();

        return view('cms::admin.home.index', ['sections' => $sections]);
    }

    public function create(HomeCollections $collections): View
    {
        return view('cms::admin.home.form', ['section' => null, 'texts' => collect(), 'collections' => $collections->options(app()->getLocale()), 'startsLocal' => null, 'endsLocal' => null]);
    }

    public function edit(HomeCollections $collections, string $section): View
    {
        $row = DB::table('cms_home_sections')->where('public_id', $section)->first() ?? abort(404);
        $zone = (string) config('app.display_timezone');
        $local = fn (?string $utc) => $utc === null ? null : CarbonImmutable::parse($utc, 'UTC')->setTimezone($zone)->format('Y-m-d\TH:i');

        return view('cms::admin.home.form', [
            'section' => $row, 'texts' => DB::table('cms_home_section_translations')->where('section_id', $row->id)->get(), 'collections' => $collections->options(app()->getLocale()),
            'startsLocal' => $local($row->starts_at), 'endsLocal' => $local($row->ends_at),
        ]);
    }

    public function store(Request $request, SaveHomeSection $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function update(Request $request, SaveHomeSection $save, string $section): RedirectResponse
    {
        return $this->persist($request, $save, $section);
    }

    public function destroy(Request $request, DeleteHomeSection $delete, string $section): RedirectResponse
    {
        try {
            $delete->handle($this->actor($request), $section);
        } catch (CmsException $e) {
            abort_if($e->reason === 'home_not_found', 404);
        }

        return redirect()->route('admin.cms.home.index')->with('status', __('cms::home.deleted'));
    }

    public function move(Request $request, MoveHomeSection $move, string $section): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        try {
            $move->handle($this->actor($request), $section, $data['direction']);
        } catch (CmsException $e) {
            abort_if($e->reason === 'home_not_found', 404);
        }

        return redirect()->route('admin.cms.home.index');
    }

    private function persist(Request $request, SaveHomeSection $save, ?string $section): RedirectResponse
    {
        $rules = ['type' => ['required', 'in:'.implode(',', SaveHomeSection::TYPES)], 'collection' => ['nullable', 'string', 'size:26'], 'active' => ['nullable', 'boolean'],
            'starts_local' => ['nullable', 'date'], 'ends_local' => ['nullable', 'date']];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["texts.{$locale}.title"] = ['nullable', 'string', 'max:160'];
            $rules["texts.{$locale}.body"] = ['nullable', 'string', 'max:20000'];   // the Action holds the real 5,000 limit, after trimming
        }
        $data = $request->validate($rules);
        $canShow = $request->user('admin')?->can('cms.publish') === true;

        try {
            $save->handle($this->actor($request), $section, (string) $data['type'], $data['collection'] ?? null, $request->has('active_present') ? $request->boolean('active') : null, $canShow,
                $data['starts_local'] ?? null, $data['ends_local'] ?? null, (array) ($data['texts'] ?? []));
        } catch (CmsException $e) {
            abort_if($e->reason === 'home_not_found', 404);

            return back()->withInput()->withErrors(['home' => __('cms::home.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.cms.home.index')->with('status', __('cms::home.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }
}
