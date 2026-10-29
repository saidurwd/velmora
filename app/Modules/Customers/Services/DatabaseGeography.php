<?php

declare(strict_types=1);

namespace App\Modules\Customers\Services;

use App\Modules\Customers\Contracts\Geography;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\DB;

final class DatabaseGeography implements Geography
{
    private const TTL = 86400; // reference data: changes only when the seed file is replaced

    public function __construct(private readonly Repository $cache) {}

    public function divisions(): array
    {
        $col = $this->column();

        return $this->cache->remember("geo:divisions:{$col}", self::TTL, fn () => array_values(DB::table('divisions')->orderBy($col)->get(['id', $col.' as name'])->map(fn ($r) => ['id' => (int) $r->id, 'name' => (string) $r->name])->all()));
    }

    public function districtsByDivision(): array
    {
        $col = $this->column();

        return $this->cache->remember("geo:districts:{$col}", self::TTL, function () use ($col): array {
            $out = [];
            foreach (DB::table('districts')->orderBy($col)->get(['id', 'division_id', $col.' as name']) as $r) {
                $out[(int) $r->division_id][] = ['id' => (int) $r->id, 'name' => (string) $r->name];
            }

            return $out;
        });
    }

    public function upazilasByDistrict(): array
    {
        $col = $this->column();

        return $this->cache->remember("geo:upazilas:{$col}", self::TTL, function () use ($col): array {
            $out = [];
            foreach (DB::table('upazilas')->orderBy($col)->get(['id', 'district_id', $col.' as name']) as $r) {
                $out[(int) $r->district_id][] = ['id' => (int) $r->id, 'name' => (string) $r->name];
            }

            return $out;
        });
    }

    public function resolve(int $divisionId, int $districtId, int $upazilaId): ?array
    {
        $row = DB::table('upazilas as u')
            ->join('districts as d', 'd.id', '=', 'u.district_id')
            ->join('divisions as v', 'v.id', '=', 'd.division_id')
            ->where('u.id', $upazilaId)->where('d.id', $districtId)->where('v.id', $divisionId)
            ->first(['v.name_en as v_en', 'v.name_bn as v_bn', 'd.name_en as d_en', 'd.name_bn as d_bn', 'u.name_en as u_en', 'u.name_bn as u_bn']);

        return $row === null ? null : [
            'division' => ['en' => (string) $row->v_en, 'bn' => (string) $row->v_bn],
            'district' => ['en' => (string) $row->d_en, 'bn' => (string) $row->d_bn],
            'upazila' => ['en' => (string) $row->u_en, 'bn' => (string) $row->u_bn],
        ];
    }

    private function column(): string
    {
        return app()->getLocale() === 'bn' ? 'name_bn' : 'name_en';
    }
}
