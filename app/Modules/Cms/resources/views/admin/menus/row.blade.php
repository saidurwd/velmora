<div class="flex flex-wrap items-center gap-3">
    <span class="font-medium">{{ $row->label ?: '—' }}</span>
    <span class="rounded bg-surface-alt px-2 py-0.5 text-xs">{{ __('cms::menu.kinds.'.$row->kind) }}</span>
    <span class="text-sm {{ $row->is_active ? 'text-success' : 'text-muted' }}">{{ $row->is_active ? __('cms::menu.shown') : __('cms::menu.hidden') }}</span>
    <span class="ms-auto flex flex-wrap items-center gap-2">
        @can('cms.edit')
            <form method="POST" action="{{ route('admin.cms.menus.move', $row->public_id) }}" class="inline">@csrf<input type="hidden" name="direction" value="up"><button class="min-h-11 rounded-md border border-line px-3 py-2" aria-label="{{ __('cms::menu.move_up') }}: {{ $row->label }}">↑</button></form>
            <form method="POST" action="{{ route('admin.cms.menus.move', $row->public_id) }}" class="inline">@csrf<input type="hidden" name="direction" value="down"><button class="min-h-11 rounded-md border border-line px-3 py-2" aria-label="{{ __('cms::menu.move_down') }}: {{ $row->label }}">↓</button></form>
        @endcan
        <a class="underline" href="{{ route('admin.cms.menus.edit', $row->public_id) }}">{{ __('cms::menu.edit') }}</a>
        @can('cms.edit')
            <form method="POST" action="{{ route('admin.cms.menus.destroy', $row->public_id) }}" class="inline" data-confirm="{{ __('cms::menu.confirm_delete') }}">@csrf @method('DELETE')<button class="underline text-danger">{{ __('cms::admin.delete') }}</button></form>
        @endcan
    </span>
</div>
