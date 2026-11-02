<?php

declare(strict_types=1);

namespace App\Modules\Orders\Contracts;

/** Order references for reports in other modules (for example "which orders used this coupon"). */
interface OrderDirectory
{
    /**
     * @param  list<int>  $orderIds
     * @return array<int, array{number: string, public_id: string}> keyed by order id
     */
    public function references(array $orderIds): array;

    /**
     * What each order line was sold as, in one query, in the language asked for (falling back to the shop's default language).
     *
     * @param  list<int>  $orderItemIds
     * @return array<int, array{name: string, sku: string}> keyed by order item id
     */
    public function itemLabels(array $orderItemIds, string $locale): array;

    /** @return array{id: int, number: string, public_id: string, phone: string, user_id: int|null}|null the order with this public id, with the phone it was placed with and its owner (null for a guest order) */
    public function findByPublicId(string $publicId): ?array;

    /** @return array{id: int, number: string, public_id: string}|null the order with this order number (as printed, any case) */
    public function findByNumber(string $orderNumber): ?array;
}
