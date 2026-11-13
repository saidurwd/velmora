@props(['id', 'title'])
{{-- Open with: $dispatch('open-modal', 'id'). Esc and the backdrop close it; focus is trapped by the dialog element. --}}
<div x-data="modal(@js($id))" x-on:open-modal.window="show($event)" x-on:keydown.escape.window="hide()" x-show="open" x-cloak style="display:none">
    <div class="fixed inset-0 z-40 bg-black/50" @click="hide()" aria-hidden="true"></div>
    <div x-ref="panel" role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" tabindex="-1" class="fixed inset-x-4 top-[10%] z-50 mx-auto max-w-lg rounded-lg bg-surface p-6 shadow-xl">
        <div class="flex items-start justify-between gap-4">
            <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
            <button type="button" @click="hide()" class="min-h-11 min-w-11 rounded hover:bg-line" aria-label="{{ __('platform::ui.close') }}">×</button>
        </div>
        <div class="mt-4">{{ $slot }}</div>
    </div>
</div>
