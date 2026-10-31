<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\OrderDirectory;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderDirectory implements OrderDirectory
{
    public function findByNumber(string $orderNumber): ?array
    {
        $row = DB::table('orders')->where('order_number', strtoupper(trim($orderNumber)))->first(['id', 'order_number', 'public_id']);

        return $row === null ? null : ['id' => (int) $row->id, 'number' => (string) $row->order_number, 'public_id' => (string) $row->public_id];
    }

    public function references(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('orders')->whereIn('id', $orderIds)->get(['id', 'order_number', 'public_id']) as $r) {
            $out[(int) $r->id] = ['number' => (string) $r->order_number, 'public_id' => (string) $r->public_id];
        }

        return $out;
    }
}
