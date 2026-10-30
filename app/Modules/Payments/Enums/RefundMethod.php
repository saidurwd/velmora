<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

enum RefundMethod: string
{
    case Gateway = 'gateway';
    case Manual = 'manual';
}
