{{-- One line icon per menu area ($key). Decorative: the area's name is always written next to it. --}}
@php
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7.5" height="9" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="5" rx="1.5"/><rect x="13.5" y="11" width="7.5" height="10" rx="1.5"/><rect x="3" y="15" width="7.5" height="6" rx="1.5"/>',
        'sales' => '<path d="M6 3h12l1 18-3.5-2-3.5 2-3.5-2L5 21Z"/><path d="M9 8h6M9 12h6M9 16h3"/>',
        'fulfilment' => '<path d="M2.5 6h11v10h-11zM13.5 9h4l3 3.5V16h-7z"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
        'catalog' => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
        'inventory' => '<path d="m12 3 8.5 4.5v9L12 21l-8.5-4.5v-9Z"/><path d="m3.5 7.5 8.5 4.5 8.5-4.5M12 12v9"/>',
        'pricing' => '<path d="m19 5-14 14"/><circle cx="7" cy="7" r="2.5"/><circle cx="17" cy="17" r="2.5"/>',
        'services' => '<rect x="3" y="4.5" width="18" height="16" rx="2"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/><path d="m9 15 2 2 4-4"/>',
        'finance' => '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 9v6M18 9v6"/>',
        'customers' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c.8-3.5 3.4-5.5 6.5-5.5s5.7 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18.5 14.8c1.6.8 2.7 2.6 3 5.2"/>',
        'content' => '<rect x="3" y="3.5" width="18" height="17" rx="2"/><path d="M3 8.5h18"/><path d="m7 16 3-3 2.5 2.5L15 13l3 3"/>',
        'reports' => '<path d="M3 21h18"/><rect x="5" y="11" width="3" height="7" rx=".8"/><rect x="10.5" y="6" width="3" height="12" rx=".8"/><rect x="16" y="13.5" width="3" height="4.5" rx=".8"/>',
        'security' => '<path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.5 7-10V6Z"/><path d="M10 11.5h4M10 11.5v3h4v-3M10.5 11.5V10a1.5 1.5 0 0 1 3 0v1.5"/>',
        'system' => '<rect x="3.5" y="4" width="17" height="6.5" rx="1.5"/><rect x="3.5" y="13.5" width="17" height="6.5" rx="1.5"/><path d="M7 7.25h.01M7 16.75h.01M11 7.25h5M11 16.75h5"/>',
        'administration' => '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6"/>',
    ];
@endphp
<svg aria-hidden="true" class="{{ $class ?? 'size-5' }} shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">{!! $paths[$key] ?? '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>' !!}</svg>
