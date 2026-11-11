<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use DomainException;

/** `reason` is a translation key under platform::productivity.saved.errors. */
final class SavedFilterException extends DomainException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function unknownScreen(): self
    {
        return new self('unknown_screen');
    }

    public static function name(): self
    {
        return new self('name_invalid');
    }

    public static function nothingToSave(): self
    {
        return new self('nothing_to_save');
    }

    public static function invalid(): self
    {
        return new self('invalid_filter');
    }

    public static function tooMany(): self
    {
        return new self('too_many');
    }

    public static function duplicate(): self
    {
        return new self('duplicate');
    }
}
