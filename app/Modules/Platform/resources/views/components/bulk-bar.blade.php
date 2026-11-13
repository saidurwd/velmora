@props(['screen'])
@php $actions = app(\App\Modules\Platform\Support\BulkActions::class)->forScreen($screen, auth('admin')->user()); @endphp
@if ($actions !== [])
    <form id="bulk-form-{{ $screen }}" method="POST" action="{{ route('admin.bulk.confirm', $actions[0]->code()) }}" class="flex flex-wrap items-end gap-3 rounded-md border border-line p-3" aria-label="{{ __('platform::bulk.bar') }}">@csrf
        <div><label for="bulk-action-{{ $screen }}" class="block text-sm font-medium">{{ __('platform::bulk.for_chosen') }}</label>
            <select id="bulk-action-{{ $screen }}" class="mt-1 block rounded-md border border-line px-3 py-2" data-bulk-action>
                @foreach ($actions as $a)<option value="{{ route('admin.bulk.confirm', $a->code()) }}">{{ __($a->label()) }}</option>@endforeach
            </select></div>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" class="size-4" data-bulk-all="bulk-form-{{ $screen }}"> {{ __('platform::bulk.select_all') }}</label>
        <x-platform::btn type="submit" size="sm" variant="secondary">{{ __('platform::bulk.go') }}</x-platform::btn>
        <p class="text-xs text-muted">{{ __('platform::bulk.max', ['max' => \App\Modules\Platform\Support\Fmt::number(\App\Modules\Platform\Support\BulkActions::MAX_ROWS)]) }}</p>
    </form>
@endif
