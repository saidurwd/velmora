@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'size' => 'md'])
@php
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-md font-medium transition-colors',
        'min-h-11 px-5 py-2' => $size === 'md',
        'min-h-9 px-3 py-1 text-sm' => $size === 'sm',
        'bg-brand-600 text-white hover:bg-brand-700' => $variant === 'primary',
        'border border-line bg-surface text-ink hover:bg-surface-alt' => $variant === 'secondary',
        'text-brand-700 underline underline-offset-2 hover:text-brand-600' => $variant === 'link',
        'bg-danger text-white hover:bg-red-800' => $variant === 'danger',
    ];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
