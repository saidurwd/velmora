@extends('layouts.account')

@section('title', __('cms::policies.account.title'))

@section('account')
<div class="max-w-2xl space-y-4">
    <h1 class="text-2xl font-bold">{{ __('cms::policies.account.title') }}</h1>
    @if (empty($notices))
        <p>{{ __('cms::policies.account.nothing') }}</p>
    @else
        <p>{{ __('cms::policies.account.intro') }}</p>
        <form method="POST" action="{{ route('account.policies.accept', ['locale' => app()->getLocale()]) }}" class="space-y-4">
            @csrf
            <ul class="space-y-2">
                @foreach ($notices as $n)
                    <li class="rounded-md border border-line p-3">
                        <strong>{{ __('cms::policies.kinds.'.$n->kind) }}</strong>
                        @if ($n->url !== null) · <a class="underline" href="{{ $n->url }}" target="_blank" rel="noopener">{{ $n->title }}</a>@endif
                        <span class="block text-sm text-muted">{{ __('cms::policies.account.version', ['number' => $n->versionNumber]) }} · {{ __('cms::policies.account.in_force', ['date' => \App\Modules\Platform\Support\Fmt::dateTime($n->effectiveAt)]) }}</span>
                    </li>
                @endforeach
            </ul>
            @include('cms::policies.accept', ['notices' => $notices])
            <x-platform::form.button>{{ __('cms::policies.account.accept') }}</x-platform::form.button>
        </form>
    @endif
</div>
@endsection
