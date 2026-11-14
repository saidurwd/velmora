@props(['id', 'name' => 'quantity', 'value' => 1, 'min' => 1, 'max' => null, 'step' => 1, 'describedby' => null, 'size' => 'md'])
{{--
    A quantity box with − and + buttons. Without JavaScript it is a plain box to type in (Bangla digits too; the server normalises them) and the buttons stay hidden,
    because they would do nothing. With JavaScript (resources/js/behaviors.js, [data-stepper]) the buttons appear, keep to the minimum, maximum and step, and the
    box becomes a spinbutton that the arrow keys change. The buttons are skipped by Tab: keyboard users already have the arrow keys, and Tab goes straight on to the
    form's button.
--}}
@php
    $box = $size === 'sm' ? 'size-9' : 'size-11';
    $field = $size === 'sm' ? 'w-10 py-1.5' : 'w-12 py-2.5';
@endphp
<div data-stepper data-min="{{ $min }}" data-step="{{ $step }}" @if ($max !== null) data-max="{{ $max }}" @endif
    {{ $attributes->class(['inline-flex items-center rounded-full border border-line bg-surface focus-within:border-brand-600 focus-within:outline-3 focus-within:outline-brand-500/35']) }}>
    <button type="button" tabindex="-1" hidden data-stepper-dec aria-label="{{ __('platform::ui.qty_less') }}" aria-controls="{{ $id }}"
        class="{{ $box }} inline-flex shrink-0 items-center justify-center rounded-full text-ink hover:bg-surface-alt disabled:cursor-not-allowed disabled:text-muted/50 disabled:hover:bg-transparent">
        <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14"/></svg>
    </button>
    <input id="{{ $id }}" name="{{ $name }}" value="{{ $value }}" inputmode="numeric" autocomplete="off" @if ($describedby) aria-describedby="{{ $describedby }}" @endif
        class="{{ $field }} border-0 bg-transparent text-center font-semibold tabular-nums focus:outline-none focus-visible:outline-none">
    <button type="button" tabindex="-1" hidden data-stepper-inc aria-label="{{ __('platform::ui.qty_more') }}" aria-controls="{{ $id }}"
        class="{{ $box }} inline-flex shrink-0 items-center justify-center rounded-full text-ink hover:bg-surface-alt disabled:cursor-not-allowed disabled:text-muted/50 disabled:hover:bg-transparent">
        <svg aria-hidden="true" class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M5 12h14M12 5v14"/></svg>
    </button>
</div>
