<?php

declare(strict_types=1);

namespace App\Modules\Platform\Outbox;

use InvalidArgumentException;

final class OutboxRegistry
{
    /** @var array<string, class-string<DomainEvent>> */
    private array $events = [];

    /** @param  class-string<DomainEvent>  $class */
    public function register(string $class): void
    {
        $name = $class::eventName();

        if (isset($this->events[$name]) && $this->events[$name] !== $class) {
            throw new InvalidArgumentException("Outbox event name [{$name}] is already registered.");
        }

        $this->events[$name] = $class;
    }

    /** @return class-string<DomainEvent>|null */
    public function resolve(string $name): ?string
    {
        return $this->events[$name] ?? null;
    }
}
