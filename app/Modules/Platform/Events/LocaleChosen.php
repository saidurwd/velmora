<?php

declare(strict_types=1);

namespace App\Modules\Platform\Events;

/** A signed-in person's language differs from what they just chose; modules that store a preference listen. */
final readonly class LocaleChosen
{
    public function __construct(public int|string $userId, public string $locale) {}
}
