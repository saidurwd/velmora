@props(['action', 'suggestUrl'])
{{-- Works as a plain GET form without JavaScript. With JavaScript it adds an ARIA combobox: type ≥ 2 letters, ↓/↑ to move, Enter to open, Esc to close. --}}
<form method="GET" action="{{ $action }}" role="search" class="relative w-full max-w-md" x-data="searchBox(@js($suggestUrl))" @keydown.escape="close()" @click.outside="close()">
    <label for="site-search" class="sr-only">{{ __('platform::ui.search') }}</label>
    <input id="site-search" name="q" type="search" value="{{ request()->routeIs('storefront.search') && is_string(request('q')) ? request('q') : '' }}" maxlength="100" autocomplete="off" placeholder="{{ __('platform::ui.search_placeholder') }}"
           role="combobox" aria-autocomplete="list" aria-haspopup="listbox" :aria-expanded="open.toString()" aria-controls="search-suggestions" :aria-activedescendant="active !== -1 ? 'sg-' + active : null"
           x-model="query" @input.debounce.250ms="fetchSuggestions()" @keydown.arrow-down.prevent="move(1)" @keydown.arrow-up.prevent="move(-1)" @keydown.enter="choose($event)"
           class="block min-h-11 w-full rounded-md border border-line bg-surface px-3 py-2 pe-12">
    <button type="submit" class="absolute inset-y-0 end-0 flex min-w-11 items-center justify-center rounded-e-md text-muted hover:text-ink" aria-label="{{ __('platform::ui.search') }}">
        <svg aria-hidden="true" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
    </button>
    <ul id="search-suggestions" role="listbox" aria-label="{{ __('platform::ui.suggestions') }}" x-show="open" x-cloak style="display:none" class="absolute inset-x-0 top-full z-30 mt-1 overflow-hidden rounded-md border border-line bg-surface shadow-lg">
        <template x-for="(item, i) in items" :key="item.url">
            <li :id="'sg-' + i" role="option" :aria-selected="(i === active).toString()" :class="i === active ? 'bg-brand-50' : ''">
                <a :href="item.url" class="block min-h-11 px-3 py-3 hover:bg-surface-alt" x-text="item.name"></a>
            </li>
        </template>
    </ul>
    <p class="sr-only" role="status" aria-live="polite" x-text="announcement"></p>
</form>
