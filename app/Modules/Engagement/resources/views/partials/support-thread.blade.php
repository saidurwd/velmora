{{-- The messages of one ticket. $messages comes from SupportReader; $fileRoute is the route that serves a file (customer or staff); $staff is true on the desk. --}}
<ol class="space-y-4">
    @foreach ($messages as $m)
        <li class="rounded-lg border p-4 {{ $m['internal'] ?? false ? 'border-warning bg-surface-alt' : 'border-line' }}">
            <div class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span class="font-medium">
                    @if ($m['mine']){{ $staff ? __('engagement::support.the_customer') : __('engagement::support.you') }}@else{{ $m['who'] !== '' ? $m['who'] : __('engagement::support.the_shop') }}@endif
                    @if ($m['internal'] ?? false)<x-platform::badge tone="neutral">{{ __('engagement::support.internal') }}</x-platform::badge>@endif
                </span>
                <span class="text-muted">{{ \App\Modules\Platform\Support\Fmt::dateTime(\Carbon\CarbonImmutable::parse($m['at'], 'UTC')) }}</span>
            </div>
            <p class="mt-2 whitespace-pre-line break-words">{{ $m['body'] }}</p>
            @if ($m['files'] !== [])
                <ul class="mt-2 flex flex-wrap gap-3 text-sm">
                    @foreach ($m['files'] as $f)
                        <li><a class="underline" href="{{ route($fileRoute, $f['id']) }}">{{ $f['name'] }}</a> <span class="text-muted">({{ __('engagement::support.size_kb', ['count' => \App\Modules\Platform\Support\Fmt::number(max(1, round($f['size'] / 1024)))]) }})</span></li>
                    @endforeach
                </ul>
            @endif
        </li>
    @endforeach
</ol>
