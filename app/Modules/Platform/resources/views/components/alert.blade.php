@props(['type' => 'info'])
<div role="status" {{ $attributes->class([
    'rounded-md border p-4 text-sm',
    'border-success bg-green-50' => $type === 'success',
    'border-line bg-surface-alt' => $type === 'info',
    'border-danger bg-red-50' => $type === 'error',
]) }}>{{ $slot }}</div>
