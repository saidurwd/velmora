<section aria-labelledby="variants-h" class="space-y-4">
    <h2 id="variants-h" class="text-lg font-semibold">{{ __('catalog::admin.products.variants') }}</h2>

    <table class="w-full text-sm">
        <caption class="sr-only">{{ __('catalog::admin.products.variants') }}</caption>
        <thead><tr class="border-b border-line">
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.variants.sku') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.variants.options') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.status') }}</th>
            <th scope="col" class="py-2 text-start">{{ __('catalog::admin.variants.default') }}</th>
            <th scope="col" class="py-2"><span class="sr-only">{{ __('catalog::admin.actions') }}</span></th>
        </tr></thead>
        <tbody>
        @foreach ($product->variants as $variant)
            <tr class="border-b border-line">
                <td class="py-2">{{ $variant->sku }}</td>
                <td class="py-2">{{ $variant->optionValues->map(fn ($ov) => $ov->label())->join(' / ') ?: '—' }}</td>
                <td class="py-2">{{ __('catalog::admin.variants.statuses.'.$variant->status) }}</td>
                <td class="py-2">{{ $variant->is_default ? __('catalog::admin.yes') : '' }}</td>
                <td class="py-2">
                    @can('catalog.update')
                        <form method="POST" action="{{ route('admin.catalog.products.variants.destroy', [$product, $variant]) }}" onsubmit="return confirm(@js(__('catalog::admin.confirm_delete')))">
                            @csrf @method('DELETE')
                            <button class="underline">{{ __('catalog::admin.delete') }}</button>
                        </form>
                    @endcan
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>

    @can('catalog.update')
        <form method="POST" action="{{ route('admin.catalog.products.variants.store', $product) }}" class="space-y-3 rounded-md border border-line p-4">
            @csrf
            <h3 class="font-semibold">{{ __('catalog::admin.variants.add') }}</h3>
            <x-platform::form.field name="sku" :label="__('catalog::admin.variants.sku')" :help="__('catalog::admin.variants.sku_help')" />
            @foreach ($usedAttributes as $attribute)
                <div class="space-y-1">
                    <label for="opt-{{ $attribute->id }}" class="block text-sm font-medium">{{ $attribute->label() }}</label>
                    <select id="opt-{{ $attribute->id }}" name="options[{{ $attribute->id }}]" class="block w-full rounded-md border border-line px-3 py-2">
                        @foreach ($optionAttributes->firstWhere('id', $attribute->id)?->values ?? [] as $value)<option value="{{ $value->id }}">{{ $value->label() }}</option>@endforeach
                    </select>
                </div>
            @endforeach
            <label class="flex items-center gap-2"><input type="checkbox" name="track_inventory" value="1" class="size-4"> {{ __('catalog::admin.variants.track') }}</label>
            <x-platform::form.button>{{ __('catalog::admin.add') }}</x-platform::form.button>
        </form>

        <form method="POST" action="{{ route('admin.catalog.products.variants.generate', $product) }}" class="space-y-3 rounded-md border border-line p-4">
            @csrf
            <h3 class="font-semibold">{{ __('catalog::admin.variants.generate') }}</h3>
            <p class="text-sm text-muted">{{ __('catalog::admin.variants.generate_help') }}</p>
            <x-platform::form.field name="sku_base" :label="__('catalog::admin.variants.sku_base')" />
            @foreach ($optionAttributes as $attribute)
                <fieldset class="space-y-1"><legend class="text-sm font-medium">{{ $attribute->label() }}</legend>
                    <div class="flex flex-wrap gap-4">
                        @foreach ($attribute->values as $value)
                            <label class="flex items-center gap-2"><input type="checkbox" name="selection[{{ $attribute->id }}][]" value="{{ $value->id }}" class="size-4"> {{ $value->label() }}</label>
                        @endforeach
                    </div>
                </fieldset>
            @endforeach
            <label class="flex items-center gap-2"><input type="checkbox" name="track_inventory" value="1" class="size-4"> {{ __('catalog::admin.variants.track') }}</label>
            <x-platform::form.button>{{ __('catalog::admin.variants.generate') }}</x-platform::form.button>
        </form>
    @endcan
</section>
