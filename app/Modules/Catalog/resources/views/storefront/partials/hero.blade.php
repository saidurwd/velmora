{{--
  The hero: one banner as it is; several as a row you swipe or scroll (CSS scroll-snap, so it works without JavaScript and with the keyboard).
  The two arrow buttons appear only when scripts run (resources/js/behaviors.js). Nothing moves by itself.
  $banners: list<BannerView>, $label: string, $lcp: bool (the first image may be the largest thing on the page).
--}}
<section aria-roledescription="carousel" aria-label="{{ $label }}" class="relative" data-carousel>
    @if (count($banners) === 1)
        @include('catalog::storefront.partials.banner', ['banner' => $banners[0], 'first' => $lcp])
    @else
        <ul class="scroll-row flex snap-x snap-mandatory gap-4 overflow-x-auto rounded-3xl" tabindex="0" data-carousel-track>
            @foreach ($banners as $banner)
                <li class="w-full shrink-0 snap-center" aria-roledescription="slide" aria-label="{{ \App\Modules\Platform\Support\Fmt::number($loop->iteration) }} / {{ \App\Modules\Platform\Support\Fmt::number(count($banners)) }}">
                    @include('catalog::storefront.partials.banner', ['banner' => $banner, 'first' => $lcp && $loop->first])
                </li>
            @endforeach
        </ul>
        <div class="absolute bottom-4 end-4 flex gap-2" hidden data-carousel-controls>
            <button type="button" data-carousel-prev class="flex size-11 items-center justify-center rounded-full bg-white/90 text-ink shadow-card hover:bg-white" aria-label="{{ __('layout.store.previous') }}"><svg aria-hidden="true" class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m12 5-5 5 5 5"/></svg></button>
            <button type="button" data-carousel-next class="flex size-11 items-center justify-center rounded-full bg-white/90 text-ink shadow-card hover:bg-white" aria-label="{{ __('layout.store.next') }}"><svg aria-hidden="true" class="size-5 rtl:rotate-180" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 5 5 5-5 5"/></svg></button>
        </div>
    @endif
</section>
