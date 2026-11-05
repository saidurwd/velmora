<?php

declare(strict_types=1);

namespace App\Modules\Privacy\Enums;

enum RequestType: string
{
    case Export = 'export';
    case Erasure = 'erasure';
}
