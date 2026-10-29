<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Web;

use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\Contracts\Geography;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Customers\Http\Requests\AddressRequest;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AddressController
{
    private const FIELD_FOR = ['phone_invalid' => 'phone', 'location_mismatch' => 'upazila_id', 'postal_invalid' => 'postal_code', 'limit_reached' => 'address_line', 'not_found' => 'address_line'];

    public function __construct(private readonly AddressBook $book, private readonly Geography $geography) {}

    public function index(Request $request): View
    {
        return view('customers::account.addresses.index', ['addresses' => $this->book->list($this->userId($request))]);
    }

    public function create(): View
    {
        return view('customers::account.addresses.form', ['address' => null, 'geo' => $this->geo()]);
    }

    public function store(AddressRequest $request): RedirectResponse
    {
        try {
            $this->book->save($this->userId($request), null, $request->address());
        } catch (AddressException $e) {
            return back()->withInput()->withErrors([self::FIELD_FOR[$e->reason] ?? 'address_line' => __('customers::account.addresses.errors.'.$e->reason, ['max' => 10])]);
        }

        return redirect()->route('account.addresses')->with('status', __('customers::account.addresses.saved'));
    }

    public function edit(Request $request, string $address): View
    {
        $view = $this->book->find($this->userId($request), $address) ?? abort(404);

        return view('customers::account.addresses.form', ['address' => $view, 'geo' => $this->geo()]);
    }

    public function update(AddressRequest $request, string $address): RedirectResponse
    {
        try {
            $this->book->save($this->userId($request), $address, $request->address());
        } catch (AddressException $e) {
            if ($e->reason === 'not_found') {
                abort(404);
            }

            return back()->withInput()->withErrors([self::FIELD_FOR[$e->reason] ?? 'address_line' => __('customers::account.addresses.errors.'.$e->reason, ['max' => 10])]);
        }

        return redirect()->route('account.addresses')->with('status', __('customers::account.addresses.saved'));
    }

    public function destroy(Request $request, string $address): RedirectResponse
    {
        $this->guard(fn () => $this->book->delete($this->userId($request), $address));

        return redirect()->route('account.addresses')->with('status', __('customers::account.addresses.deleted'));
    }

    public function makeDefault(Request $request, string $address): RedirectResponse
    {
        $this->guard(fn () => $this->book->makeDefault($this->userId($request), $address));

        return redirect()->route('account.addresses')->with('status', __('customers::account.addresses.saved'));
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (AddressException) {
            abort(404); // somebody else's address and a missing one look the same
        }
    }

    /** @return array{divisions: list<array{id: int, name: string}>, districts: array<int, list<array{id: int, name: string}>>, upazilas: array<int, list<array{id: int, name: string}>>} */
    private function geo(): array
    {
        return ['divisions' => $this->geography->divisions(), 'districts' => $this->geography->districtsByDivision(), 'upazilas' => $this->geography->upazilasByDistrict()];
    }

    private function userId(Request $request): int
    {
        return (int) $request->user('web')?->getAuthIdentifier();
    }
}
