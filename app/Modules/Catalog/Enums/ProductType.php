<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Enums;

enum ProductType: string
{
    case Physical = 'physical';
    case Digital = 'digital';
    case Service = 'service';

    public function tracksStock(): bool
    {
        return $this === self::Physical; // digital goods and services never have stock (BD-40)
    }
}
