@php
    $count = app(\App\Modules\Cart\Contracts\CartReader::class)->itemCount(app(\App\Modules\Cart\Services\CartOwners::class)->fromRequest(request()));
@endphp
<a href="{{ url('/'.app()->getLocale().'/cart') }}" class="relative inline-flex min-h-11 min-w-11 items-center justify-center gap-1 rounded-md px-2 hover:bg-surface-alt" aria-label="{{ trans_choice('cart::cart.link_label', $count, ['count' => \App\Modules\Platform\Support\Fmt::number($count)]) }}">
    <svg aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l2.7 12.4a1 1 0 0 0 1 .8h9.1a1 1 0 0 0 1-.8L21 7H6"/></svg>
    @if ($count > 0)<span aria-hidden="true" class="rounded-full bg-brand-600 px-1.5 text-xs font-semibold text-white">{{ \App\Modules\Platform\Support\Fmt::number($count) }}</span>@endif
</a>
