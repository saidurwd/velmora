<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Controllers\Api;

use App\Modules\Customers\Contracts\Geography;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Divisions, districts and upazilas, for the address form. The ids are the fixed reference numbers an address carries (`division_id`,
 * `district_id`, `upazila_id`); they are not records of any customer.
 */
class GeographyController
{
    /**
     * Divisions
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{id: int, name: string}>}')]
    public function divisions(Geography $geo): JsonResponse
    {
        return response()->json(['data' => $geo->divisions()]);
    }

    /**
     * Districts of a division
     *
     * `division` is an id from `/geo/divisions`; an unknown one is 404.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{id: int, name: string}>}')]
    public function districts(Request $request, Geography $geo): JsonResponse
    {
        $request->validate(['division' => ['required', 'integer', 'min:1']]);
        $all = $geo->districtsByDivision();
        $id = (int) $request->query('division');

        return response()->json(['data' => $all[$id] ?? throw new ApiProblem(ErrorCode::NotFound)]);
    }

    /**
     * Upazilas of a district
     *
     * `district` is an id from `/geo/districts`; an unknown one is 404.
     *
     * @unauthenticated
     */
    #[ResponseDoc(status: 200, type: 'array{data: list<array{id: int, name: string}>}')]
    public function upazilas(Request $request, Geography $geo): JsonResponse
    {
        $request->validate(['district' => ['required', 'integer', 'min:1']]);
        $all = $geo->upazilasByDistrict();
        $id = (int) $request->query('district');

        return response()->json(['data' => $all[$id] ?? throw new ApiProblem(ErrorCode::NotFound)]);
    }
}
