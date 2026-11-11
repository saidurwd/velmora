@props(['screen'])
@php
    $user = auth('admin')->user();
    $screens = app(\App\Modules\Platform\Support\SavedFilterScreens::class);
    $mine = $user && $screens->has($screen) && $user->can($screens->permission($screen)) ? app(\App\Modules\Platform\Support\SavedFilters::class)->for((int) $user->getAuthIdentifier(), $screen) : null;
    $current = $mine === null ? [] : array_filter(request()->only($screens->keys($screen)), fn ($v) => is_string($v) && trim($v) !== '');
@endphp
@if ($mine !== null)
    <section class="space-y-2 rounded-md border border-line p-3" aria-label="{{ __('platform::productivity.saved.title') }}">
        <h2 class="text-sm font-semibold">{{ __('platform::productivity.saved.title') }}</h2>
        @error('saved_filter')<p class="text-sm text-danger">{{ $message }}</p>@enderror
        @if ($mine === [])
            <p class="text-sm text-muted">{{ __('platform::productivity.saved.none') }}</p>
        @else
            <ul class="flex flex-wrap gap-2">
                @foreach ($mine as $f)
                    <li class="flex items-center gap-1 rounded-full border border-line px-3 py-1 text-sm">
                        <a href="{{ $f['url'] }}" class="underline">{{ $f['name'] }}</a>
                        <form method="POST" action="{{ route('admin.saved-filters.destroy', $f['id']) }}">@csrf @method('DELETE')<button class="text-muted hover:text-danger" aria-label="{{ __('platform::productivity.saved.delete', ['name' => $f['name']]) }}">×</button></form>
                    </li>
                @endforeach
            </ul>
        @endif
        @if ($current !== [])
            <form method="POST" action="{{ route('admin.saved-filters.store') }}" class="flex flex-wrap items-end gap-2">
                @csrf
                <input type="hidden" name="screen" value="{{ $screen }}">
                @foreach ($current as $k => $v)<input type="hidden" name="f[{{ $k }}]" value="{{ $v }}">@endforeach
                <div><label for="sf-name-{{ $screen }}" class="block text-sm">{{ __('platform::productivity.saved.name') }}</label>
                    <input id="sf-name-{{ $screen }}" name="name" maxlength="60" required class="rounded-md border border-line px-3 py-1"></div>
                <x-platform::btn type="submit" size="sm">{{ __('platform::productivity.saved.save') }}</x-platform::btn>
            </form>
        @endif
    </section>
@endif
