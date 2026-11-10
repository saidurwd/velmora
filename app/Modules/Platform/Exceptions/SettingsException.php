<?php

declare(strict_types=1);

namespace App\Modules\Platform\Exceptions;

use DomainException;

/** `reason` is a translation key under platform::settings.errors; `field` names the form field at fault, when there is one. */
final class SettingsException extends DomainException
{
    /** @param  array<string, string>  $params */
    public function __construct(public readonly string $reason, public readonly ?string $field = null, public readonly array $params = [])
    {
        parent::__construct($reason);
    }

    /** Someone else saved these settings while the form was open. */
    public static function conflict(): self
    {
        return new self('conflict');
    }

    public static function invalid(string $field, string $why = 'invalid'): self
    {
        return new self($why, $field);
    }

    public static function unknown(): self
    {
        return new self('unknown');
    }

    public static function password(): self
    {
        return new self('password', 'password');
    }

    public static function alreadyDown(): self
    {
        return new self('already_down');
    }

    public static function notDown(): self
    {
        return new self('not_down');
    }
}
