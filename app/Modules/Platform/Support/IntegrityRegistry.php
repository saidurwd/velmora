<?php

declare(strict_types=1);

namespace App\Modules\Platform\Support;

use App\Modules\Platform\Contracts\IntegrityCheck;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/** The list of data-integrity checks. Each module adds its own in its service provider; Platform runs them without knowing their tables. */
final class IntegrityRegistry
{
    /** @var list<class-string<IntegrityCheck>> */
    private array $classes = [];

    public function __construct(private readonly Container $container) {}

    /** @param  class-string<IntegrityCheck>  $class */
    public function add(string $class): void
    {
        in_array($class, $this->classes, true) || $this->classes[] = $class;
    }

    /** @return list<IntegrityCheck> */
    public function checks(): array
    {
        return array_map(fn (string $class): IntegrityCheck => $this->container->make($class), $this->classes);
    }

    public function find(string $code): IntegrityCheck
    {
        foreach ($this->checks() as $check) {
            if ($check->code() === $code) {
                return $check;
            }
        }

        throw new InvalidArgumentException("Unknown integrity check [{$code}].");
    }
}
