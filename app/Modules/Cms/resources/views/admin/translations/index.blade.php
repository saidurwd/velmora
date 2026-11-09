@extends('layouts.admin')

@section('title', __('cms::gaps.title'))

@section('content')
<div class="max-w-5xl space-y-8">
    <h1 class="text-2xl font-bold">{{ __('cms::gaps.title') }}</h1>
    <p class="text-muted">{{ __('cms::gaps.intro') }}</p>

    @foreach ($gaps as $locale => $types)
        <section aria-labelledby="g-{{ $locale }}" class="space-y-4">
            <h2 id="g-{{ $locale }}" class="text-xl font-semibold">{{ $labels[$locale] ?? strtoupper($locale) }}</h2>
            @foreach ($types as $type => $gap)
                <div class="space-y-2 rounded-md border border-line p-4">
                    <h3 class="font-semibold">{{ __('cms::gaps.types.'.$type) }}</h3>
                    <p>{{ __('cms::gaps.summary', ['missing' => $gap['missing'], 'live' => $gap['live']]) }}</p>
                    @if ($gap['rows'] === [])
                        <p class="text-success">{{ __('cms::gaps.none') }}</p>
                    @else
                        <table class="w-full text-sm">
                            <caption class="sr-only">{{ __('cms::gaps.types.'.$type) }}</caption>
                            <thead><tr class="border-b border-line">
                                <th scope="col" class="py-2 text-start">{{ __('cms::gaps.columns.item') }}</th>
                                <th scope="col" class="py-2 text-start">{{ __('cms::gaps.columns.missing') }}</th>
                            </tr></thead>
                            <tbody>
                            @foreach ($gap['rows'] as $row)
                                <tr class="border-b border-line">
                                    <td class="py-2"><a class="underline" href="{{ route($gap['route'], $row['public_id']) }}">{{ $row['title'] !== '' ? $row['title'] : '—' }}</a></td>
                                    <td class="py-2">{{ collect($row['fields'])->map(fn ($f) => __('cms::gaps.fields.'.$f))->join(', ') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        @if ($gap['capped'])<p class="text-sm text-muted">{{ __('cms::gaps.capped', ['count' => \App\Modules\Cms\Services\TranslationGaps::LIMIT]) }}</p>@endif
                    @endif
                </div>
            @endforeach
        </section>
    @endforeach
</div>
@endsection
