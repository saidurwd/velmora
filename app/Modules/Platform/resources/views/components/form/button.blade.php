@props(['variant' => 'primary', 'type' => 'submit'])
<button type="{{ $type }}" {{ $attributes->class([
    'inline-flex min-h-11 items-center justify-center rounded-full px-6 py-2 font-semibold',
    'bg-brand-600 text-white hover:bg-brand-700' => $variant === 'primary',
    'border border-line bg-surface hover:border-ink' => $variant === 'secondary',
]) }}>{{ $slot }}</button>
