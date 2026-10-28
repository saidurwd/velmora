@php $urls = app(\App\Modules\Catalog\Services\Media\MediaUrls::class); @endphp
<section aria-labelledby="media-h" class="space-y-4">
    <h2 id="media-h" class="text-lg font-semibold">{{ __('catalog::admin.media.title') }}</h2>

    @if ($product->media->isEmpty())
        <p class="text-muted">{{ __('catalog::admin.media.none') }}</p>
    @else
        <ul class="grid gap-4 sm:grid-cols-3">
            @foreach ($product->media as $media)
                <li class="space-y-2 rounded-md border border-line p-3">
                    <img src="{{ $urls->url($media, 320) }}" alt="{{ $media->is_decorative ? '' : $media->alt() }}" width="320" height="{{ (int) round(320 * $media->height / max(1, $media->width)) }}" loading="lazy" class="w-full rounded">
                    <p class="text-sm">{{ $media->is_primary ? __('catalog::admin.media.primary') : '' }} {{ $media->is_decorative ? '' : $media->alt() }}</p>
                    @can('catalog.update')
                        <div class="flex flex-wrap gap-2 text-sm">
                            @unless ($media->is_primary)
                                <form method="POST" action="{{ route('admin.catalog.products.media.primary', [$product, $media]) }}">@csrf<button class="underline">{{ __('catalog::admin.media.make_primary') }}</button></form>
                            @endunless
                            <form method="POST" action="{{ route('admin.catalog.products.media.move', [$product, $media]) }}">@csrf<button name="direction" value="up" class="underline">{{ __('catalog::admin.media.up') }}</button></form>
                            <form method="POST" action="{{ route('admin.catalog.products.media.move', [$product, $media]) }}">@csrf<button name="direction" value="down" class="underline">{{ __('catalog::admin.media.down') }}</button></form>
                            <form method="POST" action="{{ route('admin.catalog.products.media.destroy', [$product, $media]) }}" onsubmit="return confirm(@js(__('catalog::admin.confirm_delete')))">@csrf @method('DELETE')<button class="underline">{{ __('catalog::admin.delete') }}</button></form>
                        </div>
                    @endcan
                </li>
            @endforeach
        </ul>
    @endif

    @can('catalog.update')
        <form method="POST" action="{{ route('admin.catalog.products.media.store', $product) }}" enctype="multipart/form-data" class="space-y-3 rounded-md border border-line p-4">
            @csrf
            <div class="space-y-1">
                <label for="image" class="block text-sm font-medium">{{ __('catalog::admin.media.file') }}</label>
                <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" required class="block w-full">
            </div>
            @foreach ((array) config('app.supported_locales') as $locale)
                <x-platform::form.field :name="'alts['.$locale.']'" :label="__('catalog::admin.media.alt').' ('.strtoupper($locale).')'" lang="{{ $locale }}" />
            @endforeach
            <label class="flex items-center gap-2"><input type="checkbox" name="decorative" value="1" class="size-4"> {{ __('catalog::admin.media.decorative') }}</label>
            <x-platform::form.button>{{ __('catalog::admin.media.upload') }}</x-platform::form.button>
        </form>
    @endcan
</section>
