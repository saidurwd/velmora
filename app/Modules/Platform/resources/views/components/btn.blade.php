@props(['variant' => 'primary', 'href' => null, 'type' => 'button', 'size' => 'md'])
@php
    $classes = [
        'inline-flex items-center justify-center gap-2 rounded-full font-semibold transition-colors admin:rounded-lg',
        'min-h-11 px-6 py-2 admin:min-h-10 admin:px-4 admin:text-sm' => $size === 'md',
        'min-h-12 px-8 py-3 text-lg' => $size === 'lg',
        'min-h-9 px-4 py-1 text-sm admin:px-3' => $size === 'sm',
        'bg-brand-600 text-white shadow-sm hover:bg-brand-700' => $variant === 'primary',
        'border border-line bg-surface text-ink hover:border-ink admin:shadow-sm admin:hover:border-line-strong admin:hover:bg-surface-alt' => $variant === 'secondary',
        'bg-white text-ink shadow-sm hover:bg-accent-100' => $variant === 'light',
        'bg-accent-400 text-ink shadow-sm hover:bg-accent-500' => $variant === 'accent',
        'text-brand-700 underline underline-offset-2 hover:text-brand-600' => $variant === 'link',
        'bg-danger text-white hover:bg-red-800' => $variant === 'danger',
    ];
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
