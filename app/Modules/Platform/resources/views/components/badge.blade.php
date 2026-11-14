@props(['tone' => 'neutral'])
<span {{ $attributes->class([
    'inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
    'bg-surface-alt text-ink border border-line' => $tone === 'neutral',
    'bg-brand-50 text-brand-700' => $tone === 'brand',
    'bg-green-50 text-success' => $tone === 'success',
    'bg-amber-50 text-warning' => $tone === 'warning',
    'bg-red-50 text-danger' => $tone === 'danger',
    'bg-sale text-white' => $tone === 'sale',
    'bg-accent-400 text-ink' => $tone === 'accent',
    'bg-ink text-white' => $tone === 'ink',
]) }}>{{ $slot }}</span>
