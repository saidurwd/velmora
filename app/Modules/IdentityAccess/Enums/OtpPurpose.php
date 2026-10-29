<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case Register = 'register';
    case VerifyPhone = 'verify_phone';
    case Reset = 'reset';
    case GuestAccess = 'guest_access';
    case ChangePhone = 'change_phone';
    case CheckoutCod = 'checkout_cod'; // confirming a large cash-on-delivery order
}
