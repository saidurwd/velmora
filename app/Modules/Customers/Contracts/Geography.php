<?php

declare(strict_types=1);

namespace App\Modules\Customers\Contracts;

/** Divisions, districts and upazilas for address forms, checkout and shipping zones. */
interface Geography
{
    /** @return list<array{id: int, name: string}> names in the current language */
    public function divisions(): array;

    /** @return array<int, list<array{id: int, name: string}>> division id => its districts */
    public function districtsByDivision(): array;

    /** @return array<int, list<array{id: int, name: string}>> district id => its upazilas */
    public function upazilasByDistrict(): array;

    /**
     * Names in both languages when the three ids form a real division → district → upazila chain, otherwise null.
     *
     * @return array{division: array{en: string, bn: string}, district: array{en: string, bn: string}, upazila: array{en: string, bn: string}}|null
     */
    public function resolve(int $divisionId, int $districtId, int $upazilaId): ?array;
}
