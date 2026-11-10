<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\IntegrationTest;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** The connection tests the modules registered, by provider. */
final class IntegrationTests
{
    /** @var array<string, class-string<IntegrationTest>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<IntegrationTest>  $class */
    public function add(string $provider, string $class): void
    {
        $this->classes[$provider] = $class;
    }

    public function has(string $provider): bool
    {
        return isset($this->classes[$provider]);
    }

    public function for(string $provider): IntegrationTest
    {
        return $this->container->make($this->classes[$provider] ?? throw new InvalidArgumentException("No test for [{$provider}]."));
    }
}
