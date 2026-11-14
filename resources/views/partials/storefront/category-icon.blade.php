{{-- A line icon for a top-level category, chosen by its English slug ($key); anything unknown gets the tag. Decorative: the tile's name is the text. --}}
@php
    $paths = [
        'women' => '<path d="M9 3h6l-1 5 4 12H6l4-12z"/><path d="M9 3c0 1.7 1.3 3 3 3s3-1.3 3-3"/>',
        'men' => '<path d="M8 3 4 6l2 4 2-1v11h8V9l2 1 2-4-4-3-2 3h-4z"/>',
        'kids' => '<circle cx="12" cy="9" r="5"/><circle cx="6.5" cy="5.5" r="2"/><circle cx="17.5" cy="5.5" r="2"/><path d="M10 9h.01M14 9h.01M10.5 11.5c.8.7 2.2.7 3 0M8 14l-1.5 6h11L16 14"/>',
        'footwear' => '<path d="M3 16c0-3 1-5 2-7l3 1c1 2 3 3 6 3.5 3 .5 6 1 7 2.5v2H3z"/><path d="M3 18v-2M9 11l1.5-1M11.5 12l1.5-1"/>',
        'bags-accessories' => '<path d="M5 9h14l1 11H4z"/><path d="M9 9V7a3 3 0 0 1 6 0v2"/>',
        'accessories' => '<circle cx="12" cy="12" r="5"/><path d="M9.5 7.5 10 3h4l.5 4.5M9.5 16.5 10 21h4l.5-4.5M12 10v2l1.5 1"/>',
        'beauty-personal-care' => '<path d="M9 21h6V10H9zM10 10V6l4-3v7"/><path d="M18 5v4M16 7h4M5 13v3M3.5 14.5h3"/>',
        'home-living' => '<path d="M4 11v8h16v-8"/><path d="M2 12 12 4l10 8"/><path d="M10 19v-5h4v5"/>',
        'handicrafts-heritage' => '<path d="M9 3h6M10 3v3c-3 1-5 4-5 7 0 4 3 7 7 7s7-3 7-7c0-3-2-6-5-7V3"/><path d="M8 13h8M9.5 10h5M9.5 16h5"/>',
        'fitness-sports' => '<circle cx="12" cy="12" r="9"/><path d="M12 3c2.5 2.5 2.5 15.500 0 18M3 12h18M5.500 6c3 2 10 2 13 0M5.500 18c3-2 10-2 13 0"/>',
        'gifts-occasions' => '<path d="M4 11h16v9H4zM3 7h18v4H3zM12 7v13"/><path d="M12 7c-1.500-3-5-4-5-1.500S10 7 12 7zm0 0c1.500-3 5-4 5-1.500S14 7 12 7z"/>',
        'books-stationery' => '<path d="M4 5c3-1 6-1 8 1 2-2 5-2 8-1v13c-3-1-6-1-8 1-2-2-5-2-8-1z"/><path d="M12 6v13"/>',
        'digital-services' => '<circle cx="6" cy="7" r="3"/><circle cx="6" cy="17" r="3"/><path d="m8.500 8.500 12 8.500M8.500 15.500l12-8.500"/>',
        'clothing' => '<path d="M12 7a2 2 0 1 0-2-2"/><path d="M12 7v2l9 6v2H3v-2l9-6"/>',
    ];
    $path = $paths[$key ?? ''] ?? '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="8" cy="8" r="1.500"/>';
@endphp
<svg aria-hidden="true" class="{{ $class ?? 'size-7' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">{!! $path !!}</svg>
