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

    /** @var array<string, Closure(): ?Closure> a provider whose availability is decided on first use (its credentials may be saved by staff, not only set in the environment) */
    private array $resolvers = [];

    /** @var array<string, array{0: int, 1: ?Closure}> when each resolver was last asked, and what it answered */
    private array $resolved = [];

    private const RESOLVE_SECONDS = 60;

    /** @param  Closure(): PaymentGateway  $factory */
    public function register(string $provider, Closure $factory): void
    {
        $this->factories[$provider] = $factory;
        unset($this->built[$provider]);
    }

    /**
     * Registers a provider whose factory is found only when first needed. The resolver returns the factory, or null when the provider is not available.
     * A factory registered with register() always wins. The answer is kept for a minute so a long-running worker notices changed credentials.
     *
     * @param  Closure(): ?Closure  $resolver
     */
    public function registerResolver(string $provider, Closure $resolver): void
    {
        $this->resolvers[$provider] = $resolver;
        unset($this->resolved[$provider], $this->built[$provider]);
    }

    public function has(string $provider): bool
    {
        return $this->factory($provider) !== null;
    }

    /** @throws PaymentException */
    public function for(string $provider): PaymentGateway
    {
        $factory = $this->factory($provider) ?? throw new PaymentException('unknown_provider', ['provider' => $provider]);

        return $this->built[$provider] ??= $factory();
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_values(array_filter(array_unique([...array_keys($this->factories), ...array_keys($this->resolvers)]), fn (string $p): bool => $this->has($p)));
    }

    /** @return ?Closure(): PaymentGateway */
    private function factory(string $provider): ?Closure
    {
        if (isset($this->factories[$provider])) {
            return $this->factories[$provider];
        }
        if (! isset($this->resolvers[$provider])) {
            return null;
        }

        $now = now()->getTimestamp();
        if (! isset($this->resolved[$provider]) || $now - $this->resolved[$provider][0] >= self::RESOLVE_SECONDS) {
            $factory = ($this->resolvers[$provider])();
            if ($factory === null || ($this->resolved[$provider][1] ?? null) !== $factory) {
                unset($this->built[$provider]); // credentials changed or went away: build the adapter again
            }
            $this->resolved[$provider] = [$now, $factory];
        }

        return $this->resolved[$provider][1];
    }
}
