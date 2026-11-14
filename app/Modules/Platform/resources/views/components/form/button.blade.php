@props(['variant' => 'primary', 'type' => 'submit'])
<button type="{{ $type }}" {{ $attributes->class([
    'inline-flex min-h-11 items-center justify-center gap-2 rounded-full px-6 py-2 font-semibold transition-colors admin:min-h-10 admin:rounded-lg admin:px-4 admin:text-sm',
    'bg-brand-600 text-white shadow-sm hover:bg-brand-700' => $variant === 'primary',
    'border border-line bg-surface hover:border-ink admin:shadow-sm admin:hover:bg-surface-alt' => $variant === 'secondary',
    'bg-danger text-white shadow-sm hover:bg-red-800' => $variant === 'danger',
]) }}>{{ $slot }}</button>
