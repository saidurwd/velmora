<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Modules\Customers\Contracts\AddressBook;
use App\Modules\Customers\DTOs\AddressView;
use App\Modules\Customers\Exceptions\AddressException;
use App\Modules\Customers\Http\Requests\AddressRequest;
use App\Modules\Customers\Http\Resources\AddressResource;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

/** The customer's address book (at most 10 addresses). Ownership is part of every lookup: somebody else's address and a missing one are both 404. */
class AddressController
{
    private const FIELD_FOR = ['phone_invalid' => 'phone', 'location_mismatch' => 'upazila_id', 'postal_invalid' => 'postal_code'];

    public function __construct(private readonly AddressBook $book) {}

    /**
     * My addresses
     *
     * The default address first.
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<'.AddressResource::TYPE.'>}')]
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($a) => AddressResource::make($a, app()->getLocale()), $this->book->list($this->userId($request)))]);
    }

    /**
     * Save a new address
     *
     * The division, district and upazila must belong together (`GET /geo/...`). The first address, or one sent with `make_default`, becomes the default.
     */
    #[ResponseDoc(status: 201, type: 'array{data: '.AddressResource::TYPE.'}')]
    public function store(AddressRequest $request): JsonResponse
    {
        return response()->json(['data' => AddressResource::make($this->save($request, null), app()->getLocale())], 201);
    }

    /**
     * Change an address
     */
    #[ResponseDoc(status: 200, type: 'array{data: '.AddressResource::TYPE.'}')]
    public function update(AddressRequest $request, string $id): JsonResponse
    {
        return response()->json(['data' => AddressResource::make($this->save($request, $id), app()->getLocale())]);
    }

    /**
     * Delete an address
     *
     * @status 204
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->guard(fn () => $this->book->delete($this->userId($request), $id));

        return response()->noContent();
    }

    /**
     * Make an address the default
     *
     * @status 204
     */
    public function makeDefault(Request $request, string $id): Response
    {
        $this->guard(fn () => $this->book->makeDefault($this->userId($request), $id));

        return response()->noContent();
    }

    private function save(AddressRequest $request, ?string $id): AddressView
    {
        try {
            return $this->book->save($this->userId($request), $id, $request->address());
        } catch (AddressException $e) {
            throw match ($e->reason) {
                'not_found' => new ApiProblem(ErrorCode::NotFound),
                'limit_reached' => new ApiProblem(ErrorCode::AddressLimit, detail: __('customers::account.addresses.errors.limit_reached', ['max' => 10])),
                default => ValidationException::withMessages([self::FIELD_FOR[$e->reason] ?? 'address_line' => [__('customers::account.addresses.errors.'.$e->reason, ['max' => 10])]]),
            };
        }
    }

    private function guard(callable $work): void
    {
        try {
            $work();
        } catch (AddressException) {
            throw new ApiProblem(ErrorCode::NotFound); // somebody else's address and a missing one look the same
        }
    }

    private function userId(Request $request): int
    {
        return (int) $request->user()?->getAuthIdentifier();
    }
}
