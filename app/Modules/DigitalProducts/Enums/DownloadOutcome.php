<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Enums;

/** What happened to one attempt to download. */
enum DownloadOutcome: string
{
    case Granted = 'granted';
    case NotOwner = 'not_owner';
    case Revoked = 'revoked';
    case Expired = 'expired';
    case LimitReached = 'limit_reached';
    case NoFile = 'no_file';
}
