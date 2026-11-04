<?php

declare(strict_types=1);

namespace App\Modules\Bookings\Http\Controllers\Admin;

use App\Modules\Bookings\Actions\SaveCancellationPolicy;
use App\Modules\Bookings\Exceptions\BookingException;
use App\Modules\Bookings\Http\Concerns\ActsAsStaff;
use App\Modules\Bookings\Http\Requests\SavePolicyRequest;
use App\Modules\Bookings\Services\Percent;
use App\Modules\Bookings\Services\SetupReader;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The cancellation policies customers read before they book, edited by staff. Percentages are typed as people write them; the shop stores whole basis points. */
class PolicySetupController
{
    use ActsAsStaff;

    public function __construct(private readonly SetupReader $reader) {}

    public function index(): View
    {
        return view('bookings::admin.setup.policies', ['rows' => $this->reader->policies(app()->getLocale())]);
    }

    public function create(): View
    {
        return view('bookings::admin.setup.policy-form', ['p' => null, 'locales' => $this->reader->locales()]);
    }

    public function edit(Request $request, string $policy): View
    {
        $data = $this->reader->policy($policy) ?? abort(404);

        return view('bookings::admin.setup.policy-form', ['p' => $data, 'locales' => $this->reader->locales(), 'can' => $request->user('admin')?->can('bookings.manage') === true]);
    }

    public function store(SavePolicyRequest $request, SaveCancellationPolicy $save): RedirectResponse
    {
        return $this->persist($request, $save, null);
    }

    public function update(SavePolicyRequest $request, SaveCancellationPolicy $save, string $policy): RedirectResponse
    {
        return $this->persist($request, $save, $policy);
    }

    private function persist(SavePolicyRequest $request, SaveCancellationPolicy $save, ?string $policy): RedirectResponse
    {
        try {
            $windows = [];
            foreach ((array) $request->validated('windows', []) as $w) {
                $hours = trim((string) ($w['hours'] ?? ''));
                $fee = trim((string) ($w['fee'] ?? ''));
                if ($hours === '' && $fee === '') {
                    continue;
                }
                if (preg_match('/^\d{1,4}$/', $hours) !== 1) {
                    throw new BookingException('windows_invalid');
                }
                $windows[] = ['hours' => (int) $hours, 'fee_bp' => Percent::toBasisPoints($fee) ?? throw new BookingException('fee_invalid')];
            }
            $saved = $save->handle(
                $this->actor($request), $policy, Percent::toBasisPoints((string) $request->validated('late')) ?? throw new BookingException('fee_invalid'), Percent::toBasisPoints((string) $request->validated('no_show')) ?? throw new BookingException('fee_invalid'),
                (int) $request->validated('reschedule_hours'), (int) $request->validated('max_reschedules'), $request->boolean('is_default'), $windows, (array) $request->validated('words'),
            );
        } catch (BookingException $e) {
            return back()->withErrors(['setup' => __('bookings::errors.'.$e->reason, $e->params)])->withInput();
        }

        return redirect()->route('admin.bookings.setup.policies.edit', ['policy' => $saved])->with('status', __('bookings::setup.saved'));
    }
}
