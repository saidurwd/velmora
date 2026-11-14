{{--
  Why shop here: four short promises. Cash on delivery and online payment are shown ONLY when the shop really offers them right now (the same list checkout uses).
  The wording is in lang/{en,bn}/layout.php ("store"): change it there to match your own delivery and returns policy.
--}}
@php
    use App\Modules\Payments\Contracts\PaymentMethodCatalog;
    use App\Modules\Platform\Support\StorefrontCache;

    $codes = app(StorefrontCache::class)->remember('pay-codes', fn () => array_values(array_map(fn ($o) => $o->code, app(PaymentMethodCatalog::class)->options(100000, ['physical'], app()->getLocale()))));
    $props = [];
    if (in_array('cod', $codes, true)) {
        $props[] = ['cod', '<path d="M3 7h18v10H3z"/><circle cx="12" cy="12" r="2.500"/><path d="M6 10v4M18 10v4"/>'];
    }
    $props[] = ['delivery', '<path d="M3 6h11v10H3zM14 9h4l3 3.500V16h-7z"/><circle cx="7" cy="18" r="1.800"/><circle cx="17.500" cy="18" r="1.800"/>'];
    $props[] = ['returns', '<path d="M4 9h11a5 5 0 0 1 0 10H8"/><path d="m8 5-4 4 4 4"/>'];
    if (array_diff($codes, ['cod']) !== []) {
        $props[] = ['online', '<path d="M12 3 5 6v5c0 4.500 3 8 7 10 4-2 7-5.500 7-10V6z"/><path d="m9 12 2 2 4-4"/>'];
    }
    if (count($props) < 4) {
        $props[] = ['lang', '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3 3 15 0 18M12 3c-3 3-3 15 0 18"/>'];
    }
@endphp
<section aria-label="{{ __('layout.store.why_us') }}" class="rounded-2xl border border-line bg-surface-alt">
    <ul class="grid grid-cols-2 divide-line lg:grid-cols-4 lg:divide-x">
        @foreach (array_slice($props, 0, 4) as [$name, $icon])
            <li class="flex items-center gap-3 p-4 lg:px-6">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-600 text-white">
                    <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.800" stroke-linecap="round" stroke-linejoin="round">{!! $icon !!}</svg>
                </span>
                <span class="min-w-0"><span class="block text-sm font-semibold sm:text-base">{{ __('layout.store.'.$name.'_title') }}</span><span class="block text-xs text-muted sm:text-sm">{{ __('layout.store.'.$name.'_text') }}</span></span>
            </li>
        @endforeach
    </ul>
</section>
