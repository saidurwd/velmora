<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\PaymentException;
use Closure;

/** Finds the adapter for a provider name (the `provider` of a payment method). Adapters are built on first use. */
final class GatewayRegistry
{
    /** @var array<string, Closure(): PaymentGateway> */
    private array $factories = [];

    /** @var array<string, PaymentGateway> */
    private array $built = [];

    /** @param  Closure(): PaymentGateway  $factory */
    public function register(string $provider, Closure $factory): void
    {
        $this->factories[$provider] = $factory;
        unset($this->built[$provider]);
    }

    public function has(string $provider): bool
    {
        return isset($this->factories[$provider]);
    }

    /** @throws PaymentException */
    public function for(string $provider): PaymentGateway
    {
        $factory = $this->factories[$provider] ?? throw new PaymentException('unknown_provider', ['provider' => $provider]);

        return $this->built[$provider] ??= $factory();
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->factories);
    }
}
