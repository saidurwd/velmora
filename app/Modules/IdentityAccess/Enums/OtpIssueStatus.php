<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum OtpIssueStatus: string
{
    case Sent = 'sent';
    case CoolingDown = 'cooling_down';       // resend too soon (safe to tell the user: applies to any number)
    case TargetLimited = 'target_limited';   // too many sends to this number
    case IpLimited = 'ip_limited';
    case DailyCapReached = 'daily_cap_reached';
    case DeliveryFailed = 'delivery_failed';
}
