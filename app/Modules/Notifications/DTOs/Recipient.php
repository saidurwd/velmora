<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/** Who a message is for: how to reach them, in what language, and (if they have an account) which one, since their preferences decide marketing messages. */
final readonly class Recipient
{
    public function __construct(public ?string $phone = null, public ?string $email = null, public string $locale = 'en', public ?int $userId = null) {}
}
