<?php

declare(strict_types=1);

namespace App\Modules\Shipping\Services;

use App\Modules\Shipping\Contracts\CourierProvider;
use App\Modules\Shipping\Exceptions\CourierException;
use Closure;

/** Finds the adapter for a courier name (the `courier` of a courier account and of a shipment). Adapters are built on first use. */
final class CourierRegistry
{
    /** @var array<string, Closure(): CourierProvider> */
    private array $factories = [];

    /** @var array<string, CourierProvider> */
    private array $built = [];

    /** @param  Closure(): CourierProvider  $factory */
    public function register(string $courier, Closure $factory): void
    {
        $this->factories[$courier] = $factory;
        unset($this->built[$courier]);
    }

    public function has(string $courier): bool
    {
        return isset($this->factories[$courier]);
    }

    /** @throws CourierException */
    public function for(string $courier): CourierProvider
    {
        $factory = $this->factories[$courier] ?? throw new CourierException('unknown_courier', ['courier' => $courier]);

        return $this->built[$courier] ??= $factory();
    }

    /** @return list<string> */
    public function couriers(): array
    {
        return array_keys($this->factories);
    }
}
