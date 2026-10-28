<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use DomainException;

final class MoneyException extends DomainException
{
    public static function currencyMismatch(string $a, string $b): self
    {
        return new self("Currency mismatch: {$a} vs {$b}.");
    }

    public static function overflow(): self
    {
        return new self('Money arithmetic overflowed the 64-bit integer range.');
    }

    public static function invalid(string $message): self
    {
        return new self($message);
    }
}
