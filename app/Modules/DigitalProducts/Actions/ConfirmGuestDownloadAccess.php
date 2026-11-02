<?php

declare(strict_types=1);

namespace App\Modules\DigitalProducts\Actions;

use App\Modules\DigitalProducts\Services\GuestDownloadAccess;
use App\Modules\IdentityAccess\Contracts\PhoneConfirmation;
use App\Modules\IdentityAccess\Enums\OtpIssueStatus;
use App\Modules\IdentityAccess\Enums\OtpPurpose;
use App\Modules\IdentityAccess\Enums\OtpVerifyResult;
use App\Modules\Orders\Contracts\DigitalOrderLines;
use App\Modules\Orders\Contracts\OrderDirectory;
use App\Modules\Platform\DTOs\PhoneNumber;

/**
 * A guest's way to their downloads: a code by SMS to the phone the order was placed with, then a short session for that one order. Sending is rate limited
 * and a check is only ever yes or no (all of that lives in the code service). An order that has nothing to download is treated as not there.
 */
final class ConfirmGuestDownloadAccess
{
    public function __construct(private readonly OrderDirectory $orders, private readonly DigitalOrderLines $lines, private readonly PhoneConfirmation $confirmation, private readonly GuestDownloadAccess $access) {}

    /** @return array{id: int, number: string, public_id: string, phone: string, user_id: int|null}|null */
    public function order(string $orderPublicId): ?array
    {
        $order = $this->orders->findByPublicId($orderPublicId);

        return $order !== null && $this->lines->paidLinesFor($order['id']) !== [] ? $order : null;
    }

    public function send(string $orderPublicId, ?string $ip, string $locale): ?OtpIssueStatus
    {
        $order = $this->order($orderPublicId);

        return $order === null ? null : $this->confirmation->send(PhoneNumber::parse($order['phone']), $ip, $locale, OtpPurpose::GuestAccess);
    }

    public function verify(string $orderPublicId, string $code): bool
    {
        $order = $this->order($orderPublicId);
        if ($order === null || $this->confirmation->check(PhoneNumber::parse($order['phone']), $code, OtpPurpose::GuestAccess) !== OtpVerifyResult::Verified) {
            return false;
        }
        $this->access->grant($orderPublicId);

        return true;
    }
}
