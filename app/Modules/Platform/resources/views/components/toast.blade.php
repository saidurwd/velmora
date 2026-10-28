@props(['type' => 'info'])
{{-- Announced politely to screen readers; dismissible; works without JS (stays visible). --}}
<div x-data="{ open: true }" x-show="open" role="status" aria-live="polite"
     {{ $attributes->class([
        'flex items-start justify-between gap-3 rounded-md border p-3 text-sm shadow-md',
        'border-success bg-green-50' => $type === 'success',
        'border-line bg-surface' => $type === 'info',
        'border-danger bg-red-50' => $type === 'error',
     ]) }}>
    <span>{{ $slot }}</span>
    <button type="button" @click="open = false" class="min-h-6 min-w-6 rounded hover:bg-line" aria-label="{{ __('platform::ui.close') }}">×</button>
</div>
