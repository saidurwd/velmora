@props(['variant' => 'primary', 'type' => 'submit'])
<button type="{{ $type }}" {{ $attributes->class([
    'inline-flex min-h-11 items-center justify-center rounded-md px-5 py-2 font-medium',
    'bg-brand-600 text-white hover:bg-brand-700' => $variant === 'primary',
    'border border-line bg-surface hover:bg-surface-alt' => $variant === 'secondary',
]) }}>{{ $slot }}</button>
