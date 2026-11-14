{{-- A person on a security screen: initials, name and whether they are staff or a customer. $person: PersonRef|null; $fallback: text when unknown. --}}
@if ($person)
    <span class="inline-flex min-w-0 items-center gap-2">
        <span aria-hidden="true" @class(['flex size-7 shrink-0 items-center justify-center rounded-full text-[0.6875rem] font-bold', 'bg-brand-600 text-white' => $person->isStaff, 'bg-accent-100 text-accent-800' => ! $person->isStaff])>{{ mb_strtoupper(collect(preg_split('/\s+/u', trim($person->name)) ?: [])->filter()->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) }}</span>
        <span class="min-w-0">
            <span class="block truncate font-medium">{{ $person->name }}</span>
            <span class="block text-xs text-muted">{{ $person->isStaff ? __('platform::security.kind.staff') : __('platform::security.kind.customer') }}</span>
        </span>
    </span>
@else
    <span class="text-muted">{{ $fallback ?? __('platform::security.unknown_person') }}</span>
@endif
