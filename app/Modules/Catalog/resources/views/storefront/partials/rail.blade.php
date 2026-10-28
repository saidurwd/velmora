<section aria-labelledby="{{ $id }}-h" class="space-y-4">
    <div class="flex items-end justify-between gap-4">
        <h2 id="{{ $id }}-h" class="text-2xl font-bold">{{ $title }}</h2>
        <a href="{{ $url }}" class="text-brand-700 underline underline-offset-2">{{ __('catalog::storefront.see_all') }}</a>
    </div>
    <ul class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach ($cards as $card)<li>@include('catalog::storefront.partials.card', ['card' => $card])</li>@endforeach
    </ul>
</section>
