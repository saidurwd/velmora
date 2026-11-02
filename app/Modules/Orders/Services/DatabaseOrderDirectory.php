<?php

declare(strict_types=1);

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Contracts\OrderDirectory;
use Illuminate\Support\Facades\DB;

final class DatabaseOrderDirectory implements OrderDirectory
{
    public function findByPublicId(string $publicId): ?array
    {
        $row = DB::table('orders')->where('public_id', $publicId)->first(['id', 'order_number', 'public_id', 'contact_phone', 'user_id']);

        return $row === null ? null : ['id' => (int) $row->id, 'number' => (string) $row->order_number, 'public_id' => (string) $row->public_id, 'phone' => (string) $row->contact_phone, 'user_id' => $row->user_id === null ? null : (int) $row->user_id];
    }

    public function findByNumber(string $orderNumber): ?array
    {
        $row = DB::table('orders')->where('order_number', strtoupper(trim($orderNumber)))->first(['id', 'order_number', 'public_id']);

        return $row === null ? null : ['id' => (int) $row->id, 'number' => (string) $row->order_number, 'public_id' => (string) $row->public_id];
    }

    public function itemLabels(array $orderItemIds, string $locale): array
    {
        if ($orderItemIds === []) {
            return [];
        }

        $out = [];
        foreach (DB::table('order_items')->whereIn('id', $orderItemIds)->get(['id', 'name_snapshot', 'sku_snapshot']) as $r) {
            $names = (array) json_decode((string) $r->name_snapshot, true);
            $out[(int) $r->id] = ['name' => (string) ($names[$locale] ?? $names[(string) config('app.default_locale')] ?? (reset($names) ?: '')), 'sku' => (string) $r->sku_snapshot];
        }

        return $out;
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
