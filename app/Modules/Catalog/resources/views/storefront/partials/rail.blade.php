{{-- A row of products with a heading. Phones: swipe sideways (two and a bit cards show, so it is clear there are more); larger screens: a grid. --}}
<section aria-labelledby="{{ $id }}-h" class="space-y-5">
    <div class="flex items-end justify-between gap-4">
        <h2 id="{{ $id }}-h" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h2>
        <a href="{{ $url }}" class="inline-flex min-h-11 shrink-0 items-center gap-1 rounded-full border border-line px-4 text-sm font-semibold hover:border-ink">{{ __('catalog::storefront.see_all') }}<span class="sr-only"> — {{ $title }}</span><svg aria-hidden="true" class="size-4 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 5 5 5-5 5"/></svg></a>
    </div>
    <ul class="scroll-row -mx-4 flex snap-x gap-3 overflow-x-auto px-4 pb-2 md:mx-0 md:grid md:grid-cols-4 md:gap-5 md:overflow-visible md:px-0 md:pb-0">
        @foreach ($cards as $card)<li class="w-[44%] shrink-0 snap-start md:w-auto">@include('catalog::storefront.partials.card', ['card' => $card])</li>@endforeach
    </ul>
</section>
