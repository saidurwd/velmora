<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Admin;

use App\Modules\Catalog\Actions\SaveAttribute;
use App\Modules\Catalog\Exceptions\CatalogException;
use App\Modules\Catalog\Http\Requests\AttributeRequest;
use App\Modules\Catalog\Models\Attribute;
use App\Modules\Platform\DTOs\Actor;
use App\Modules\Platform\Enums\ActorType;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AttributeController
{
    public function index(): View
    {
        return view('catalog::admin.attributes.index', ['attributes' => Attribute::query()->with('translations')->withCount('values')->orderBy('code')->paginate(50)]);
    }

    public function create(): View
    {
        return view('catalog::admin.attributes.form', ['attribute' => null, 'valueLines' => '']);
    }

    public function store(AttributeRequest $request, SaveAttribute $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function edit(string $attribute): View
    {
        $model = Attribute::query()->with(['translations', 'values.translations'])->where('code', $attribute)->firstOrFail();
        $locales = array_values((array) config('app.supported_locales'));

        $lines = $model->values->map(function ($value) use ($locales): string {
            $labels = array_map(fn ($l) => (string) ($value->translations->firstWhere('locale', $l)->label ?? ''), $locales);

            return implode(' | ', [$value->code, ...$labels]);
        })->implode("\n");

        return view('catalog::admin.attributes.form', ['attribute' => $model, 'valueLines' => $lines]);
    }

    public function update(AttributeRequest $request, string $attribute, SaveAttribute $save): RedirectResponse
    {
        return $this->persist($request, $save, Attribute::query()->where('code', $attribute)->firstOrFail());
    }

    private function persist(AttributeRequest $request, SaveAttribute $save, ?Attribute $attribute): RedirectResponse
    {
        try {
            $save->handle($this->actor($request), $attribute, (string) $request->validated('code'), (string) $request->validated('kind'), $request->boolean('is_filterable'), $request->names(), $request->valueRows());
        } catch (CatalogException $e) {
            return back()->withInput()->withErrors(['code' => $e->getMessage()]);
        }

        return redirect()->route('admin.catalog.attributes.index')->with('status', __('catalog::admin.saved'));
    }

    private function actor(Request $request): Actor
    {
        $user = $request->user('admin');
        abort_if($user === null, 403);

        return new Actor(ActorType::Staff, (int) $user->getAuthIdentifier(), $user->public_id, $user->name);
    }
}
