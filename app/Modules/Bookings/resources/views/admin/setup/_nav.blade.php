<nav aria-label="{{ __('bookings::setup.nav') }}" class="flex flex-wrap gap-x-5 gap-y-1">
    @foreach ([['admin.bookings.setup.services', 'services'], ['admin.bookings.setup.resources', 'resources'], ['admin.bookings.setup.holidays', 'holidays'], ['admin.bookings.setup.policies', 'policies']] as [$route, $key])
        <a href="{{ route($route) }}" class="inline-flex min-h-11 items-center {{ request()->routeIs($route.'*') ? 'font-semibold underline' : 'underline' }}" @if (request()->routeIs($route.'*')) aria-current="page" @endif>{{ __('bookings::setup.tab.'.$key) }}</a>
    @endforeach
</nav>
