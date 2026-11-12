<?php

declare(strict_types=1);

namespace App\Modules\Notifications\DTOs;

/** What one phone is shown: a title, a short line, and a target the app opens (`order:<public id>`). Nothing else travels: no amounts, addresses, phone numbers, codes or keys. */
final readonly class PushMessage
{
    /** @param  array<string, string>  $data  string keys and values only (the provider's rule); the target and the message type */
    public function __construct(public string $title, public string $body, public array $data = []) {}
}
