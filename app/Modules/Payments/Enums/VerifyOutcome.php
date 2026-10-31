<?php

declare(strict_types=1);

namespace App\Modules\Payments\Enums;

/** What VerifyPayment concluded. Everything other than Paid is "not paid (yet)". */
enum VerifyOutcome: string
{
    case Paid = 'paid';
    case Pending = 'pending';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case NeedsReview = 'needs_review';
    case UnknownReference = 'unknown_reference';
    case ProviderError = 'provider_error';   // the gateway could not be asked right now: nothing changed, try again later
    case NoChange = 'no_change';             // the gateway has no answer yet (or it was already settled)

    public function isPaid(): bool
    {
        return $this === self::Paid;
    }
}
