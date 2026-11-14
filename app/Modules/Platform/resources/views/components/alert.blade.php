@props(['type' => 'info'])
{{-- A message on the page: success, information or a problem. The icon only repeats what the words say. --}}
<div role="status" {{ $attributes->class([
    'flex items-start gap-3 rounded-md border p-4 text-sm',
    'border-success/40 bg-green-50 text-green-950' => $type === 'success',
    'border-line bg-surface-alt' => $type === 'info',
    'border-danger/40 bg-red-50 text-red-950' => $type === 'error',
    'border-warning/40 bg-amber-50 text-amber-950' => $type === 'warning',
]) }}>
    <svg aria-hidden="true" @class(['mt-0.5 size-5 shrink-0', 'text-success' => $type === 'success', 'text-muted' => $type === 'info', 'text-danger' => $type === 'error', 'text-warning' => $type === 'warning']) viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        @if ($type === 'success')<circle cx="12" cy="12" r="9"/><path d="m8 12.5 2.5 2.5L16 9.5"/>
        @elseif ($type === 'error' || $type === 'warning')<path d="M12 3.5 21.5 20h-19Z"/><path d="M12 10v4M12 17h.01"/>
        @else<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>
        @endif
    </svg>
    <div class="min-w-0 flex-1">{{ $slot }}</div>
</div>
