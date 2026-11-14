@props(['amount', 'compareAt' => null, 'size' => 'md'])
@php
    /** @var \App\Modules\Platform\DTOs\Money $amount */
    $onSale = $compareAt && $compareAt->greaterThan($amount);
@endphp
<span {{ $attributes->class(['inline-flex flex-wrap items-baseline gap-x-2', 'text-lg' => $size === 'md', 'text-3xl' => $size === 'lg', 'text-base' => $size === 'sm']) }}>
    <span class="font-bold {{ $onSale ? 'text-sale' : 'text-ink' }}">{{ \App\Modules\Platform\Support\Fmt::money($amount) }}</span>
    @if ($onSale)
        <span class="{{ $size === 'lg' ? 'text-base' : 'text-sm' }} text-muted line-through"><span class="sr-only">{{ __('platform::ui.was') }}</span>{{ \App\Modules\Platform\Support\Fmt::money($compareAt) }}</span>
    @endif
</span>
