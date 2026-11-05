<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Contracts;

/**
 * A module's part of erasing a customer. It must be safe to run twice (a half-finished erasure is simply run again) and must keep the financial record:
 * amounts, dates, items, tax and payments stay; names, numbers, addresses and the addresses of devices go.
 */
interface PersonalDataEraser
{
    public function section(): string;

    /**
     * Reasons this person cannot be erased yet (an order still on its way, an upcoming booking). Codes, translated by the screens.
     *
     * @return list<string>
     */
    public function blockers(int $userId): array;

    public function erase(int $userId): void;
}
