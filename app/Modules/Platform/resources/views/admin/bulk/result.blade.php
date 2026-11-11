@extends('layouts.admin')

@section('title', __('platform::bulk.result_title'))

@section('content')
<div class="max-w-3xl space-y-6">
    <h1 class="text-2xl font-bold">{{ __('platform::bulk.result_title') }}</h1>
    <x-platform::alert :type="$result->failed() === 0 ? 'success' : 'warning'">{{ __('platform::bulk.result_summary', ['done' => \App\Modules\Platform\Support\Fmt::number($result->done()), 'failed' => \App\Modules\Platform\Support\Fmt::number($result->failed())]) }}</x-platform::alert>
    <table class="w-full text-start text-sm">
        <caption class="sr-only">{{ __('platform::bulk.result_title') }}</caption>
        <thead><tr class="border-b border-line"><th scope="col" class="py-2 pe-3 text-start">{{ __('platform::bulk.row') }}</th><th scope="col" class="py-2 pe-3 text-start">{{ __('platform::bulk.outcome') }}</th></tr></thead>
        <tbody>
        @foreach ($result->items as $i)
            <tr class="border-b border-line align-top">
                <td class="py-2 pe-3">{{ $i['label'] }}</td>
                <td class="py-2 pe-3">@if ($i['ok'])<x-platform::badge tone="success">{{ __('platform::bulk.done') }}</x-platform::badge>@else<x-platform::badge tone="danger">{{ __('platform::bulk.not_done') }}</x-platform::badge> {{ $i['message'] ?? __('platform::bulk.not_found') }}@endif</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
