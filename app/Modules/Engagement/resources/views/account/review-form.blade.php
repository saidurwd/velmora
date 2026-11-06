@extends('layouts.account')

@section('title', __('engagement::reviews.write_title'))

@section('account')
<div class="max-w-xl space-y-6">
    <p><a class="underline" href="{{ route('account.orders.show', $order) }}">{{ __('engagement::reviews.back_to_order') }}</a></p>
    <h1 class="text-2xl font-bold">{{ __('engagement::reviews.write_title') }}</h1>
    <p class="font-medium">{{ $name }}</p>
    <p class="text-sm text-muted">{{ __('engagement::reviews.moderation_note') }}</p>
    @error('review')<x-platform::alert type="error">{{ $message }}</x-platform::alert>@enderror

    <form method="POST" action="{{ route('account.reviews.store', ['order' => $order, 'variant' => $variant]) }}" class="space-y-5" novalidate>
        @csrf
        <fieldset>
            <legend class="font-medium">{{ __('engagement::reviews.rating') }}</legend>
            <div class="mt-2 flex flex-wrap gap-4">
                @foreach ([5, 4, 3, 2, 1] as $star)
                    <label class="flex items-center gap-2"><input type="radio" name="rating" value="{{ $star }}" @checked((int) old('rating') === $star) required class="size-4"><span>{{ __('engagement::reviews.stars', ['count' => \App\Modules\Platform\Support\Fmt::number($star)]) }}</span></label>
                @endforeach
            </div>
        </fieldset>
        <x-platform::form.field name="title" :value="old('title')" :label="__('engagement::reviews.title_label')" maxlength="100" />
        <div>
            <label for="body" class="block text-sm font-medium">{{ __('engagement::reviews.body_label') }}</label>
            <textarea id="body" name="body" rows="6" minlength="10" maxlength="2000" required class="mt-1 block w-full rounded-md border border-line px-3 py-2">{{ old('body') }}</textarea>
            <p class="mt-1 text-sm text-muted">{{ __('engagement::reviews.body_help') }}</p>
        </div>
        <x-platform::form.button>{{ __('engagement::reviews.send') }}</x-platform::form.button>
    </form>
</div>
@endsection
