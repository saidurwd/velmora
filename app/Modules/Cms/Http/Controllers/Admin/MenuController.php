<?php

declare(strict_types=1);

namespace App\Modules\Cms\Http\Controllers\Admin;

use App\Modules\Cms\Actions\DeleteMenuItem;
use App\Modules\Cms\Actions\MoveMenuItem;
use App\Modules\Cms\Actions\SaveMenuItem;
use App\Modules\Cms\Exceptions\CmsException;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The header and footer menus. Looking = cms.view, writing and ordering = cms.edit, showing or hiding an item = cms.publish (a new item starts hidden). */
class MenuController
{
    public function index(): View
    {
        $default = (string) config('app.default_locale');
        $items = DB::table('cms_menu_items as i')->leftJoin('cms_menu_item_translations as t', fn ($j) => $j->on('t.item_id', '=', 'i.id')->where('t.locale', '=', $default))
            ->orderBy('i.sort_order')->orderBy('i.id')->get(['i.id', 'i.public_id', 'i.menu', 'i.parent_id', 'i.kind', 'i.is_active', 't.label']);

        $menus = [];
        foreach (SaveMenuItem::MENUS as $menu) {
            $rows = $items->where('menu', $menu);
            $menus[$menu] = $rows->whereNull('parent_id')->map(fn ($row) => ['item' => $row, 'children' => $rows->where('parent_id', $row->id)->values()->all()])->values()->all();
        }

        return view('cms::admin.menus.index', ['menus' => $menus]);
    }

    public function create(Request $request): View
    {
        $menu = in_array($request->query('menu'), SaveMenuItem::MENUS, true) ? (string) $request->query('menu') : 'footer';

        return view('cms::admin.menus.form', $this->formData(null) + ['menu' => $menu]);
    }

    public function edit(string $item): View
    {
        $row = DB::table('cms_menu_items')->where('public_id', $item)->first() ?? abort(404);

        return view('cms::admin.menus.form', $this->formData($row) + ['menu' => $row->menu]);
    }

    public function store(Request $request, SaveMenuItem $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function update(Request $request, SaveMenuItem $save, string $item): RedirectResponse
    {
        return $this->persist($request, $save, $item);
    }

    public function destroy(Request $request, DeleteMenuItem $delete, string $item): RedirectResponse
    {
        try {
            $delete->handle($this->actor($request), $item);
        } catch (CmsException $e) {
            abort_if($e->reason === 'menu_not_found', 404);
        }

        return redirect()->route('admin.cms.menus.index')->with('status', __('cms::menu.deleted'));
    }

    public function move(Request $request, MoveMenuItem $move, string $item): RedirectResponse
    {
        $data = $request->validate(['direction' => ['required', 'in:up,down']]);
        try {
            $move->handle($this->actor($request), $item, $data['direction']);
        } catch (CmsException $e) {
            abort_if($e->reason === 'menu_not_found', 404);
        }

        return redirect()->route('admin.cms.menus.index');
    }

    private function persist(Request $request, SaveMenuItem $save, ?string $item): RedirectResponse
    {
        $default = (string) config('app.default_locale');
        $rules = [
            'menu' => ['required', 'in:header,footer'], 'kind' => ['required', 'in:page,url,group'], 'parent' => ['nullable', 'string', 'size:26'], 'page' => ['nullable', 'string', 'size:26'],
            'active' => ['nullable', 'boolean'], "texts.{$default}.label" => ['required', 'string', 'max:120'],
        ];
        foreach ((array) config('app.supported_locales') as $locale) {
            $rules["texts.{$locale}.label"] ??= ['nullable', 'string', 'max:120'];
            $rules["texts.{$locale}.url"] = ['nullable', 'string', 'max:500'];
        }
        $data = $request->validate($rules);
        $canShow = $request->user('admin')?->can('cms.publish') === true;

        try {
            $save->handle($this->actor($request), $item, (string) $data['menu'], (string) $data['kind'], $data['parent'] ?? null, $data['page'] ?? null,
                $request->has('active_present') ? $request->boolean('active') : null, $canShow, (array) $data['texts']);
        } catch (CmsException $e) {
            abort_if($e->reason === 'menu_not_found', 404);

            return back()->withInput()->withErrors(['menu' => __('cms::menu.errors.'.$e->reason, $e->params)]);
        }

        return redirect()->route('admin.cms.menus.index')->with('status', __('cms::menu.saved'));
    }

    /** @return array<string, mixed> */
    private function formData(?\stdClass $row): array
    {
        $default = (string) config('app.default_locale');
        $pages = DB::table('cms_pages as p')->join('cms_page_versions as v', 'v.id', '=', 'p.draft_version_id')
            ->join('cms_page_version_translations as t', fn ($j) => $j->on('t.version_id', '=', 'v.id')->where('t.locale', '=', $default))
            ->whereNull('p.deleted_at')->orderBy('t.title')->limit(500)->get(['p.public_id', 't.title']);
        $groups = DB::table('cms_menu_items as i')->join('cms_menu_item_translations as t', fn ($j) => $j->on('t.item_id', '=', 'i.id')->where('t.locale', '=', $default))
            ->where('i.kind', 'group')->orderBy('i.menu')->orderBy('i.sort_order')->get(['i.public_id', 'i.menu', 't.label']);

        return [
            'item' => $row, 'pages' => $pages, 'groups' => $groups,
            'texts' => $row === null ? collect() : DB::table('cms_menu_item_translations')->where('item_id', $row->id)->get(),
            'pagePublicId' => $row?->page_id === null ? null : DB::table('cms_pages')->where('id', $row->page_id)->value('public_id'),
            'parentPublicId' => $row?->parent_id === null ? null : DB::table('cms_menu_items')->where('id', $row->parent_id)->value('public_id'),
        ];
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, (string) $user->name);
    }
}
