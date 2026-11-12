<?php

declare(strict_types=1);

namespace App\Modules\Orders\DTOs;

/** An order the caller may act on, and how they got in: as its signed-in owner, or by holding the order's guest token (which lets them pay, look and track, nothing more). */
final readonly class OrderAccess
{
    public function __construct(public int $orderId, public string $publicId, public ?int $ownerUserId, public bool $isOwner) {}
}
