{{-- Shop by category: icon tiles. Phones: one row to swipe; larger screens: a grid. $id, $title, $categories: list<array{name, url, key}>. --}}
@php $tints = ['bg-brand-50 text-brand-700', 'bg-accent-100 text-accent-800', 'bg-rose-50 text-rose-800', 'bg-sky-50 text-sky-800', 'bg-violet-50 text-violet-800', 'bg-lime-50 text-lime-800']; @endphp
<section aria-labelledby="{{ $id }}-h" class="space-y-5">
    <h2 id="{{ $id }}-h" class="text-2xl font-bold tracking-tight sm:text-3xl">{{ $title }}</h2>
    <ul class="scroll-row -mx-4 flex snap-x gap-3 overflow-x-auto px-4 sm:mx-0 sm:grid sm:grid-cols-4 sm:overflow-visible sm:px-0 lg:grid-cols-6">
        @foreach ($categories as $category)
            <li class="w-28 shrink-0 snap-start sm:w-auto">
                <a href="{{ $category['url'] }}" class="group flex h-full flex-col items-center gap-3 rounded-2xl border border-line bg-surface p-4 text-center transition-shadow hover:shadow-lift">
                    <span class="flex size-16 items-center justify-center rounded-full transition-transform group-hover:scale-110 {{ $tints[$loop->index % count($tints)] }}">
                        @include('partials.storefront.category-icon', ['key' => $category['key'] ?? ''])
                    </span>
                    <span class="text-sm font-semibold leading-snug">{{ $category['name'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</section>
