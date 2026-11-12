<?php

declare(strict_types=1);

namespace App\Modules\Engagement\Http;

use App\Modules\Engagement\Exceptions\EngagementException;
use App\Modules\Platform\Enums\ErrorCode;
use App\Modules\Platform\Http\ApiProblem;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Turns what a wishlist, review or support Action refused into a stable code, with the website's own wording as the explanation. Something not found (or not the caller's) is always the same plain 404. */
final class EngagementProblems
{
    /** Words that mean "what you typed is not acceptable", so they point at the field. */
    private const FIELD_ERRORS = ['invalid' => 'subject', 'too_short' => 'body', 'too_long' => 'body', 'links_not_allowed' => 'body', 'repeat_text' => 'body', 'reason_required' => 'reason'];

    /** @param  string  $lang  the translation file with the reasons: `errors` (wishlist, reviews) or `support.errors` */
    public static function from(EngagementException $e, string $lang = 'errors'): Throwable
    {
        $detail = (string) __('engagement::'.$lang.'.'.$e->reason, $e->params);

        if ($e->reason === 'not_found') {
            return new ApiProblem(ErrorCode::NotFound);
        }
        if (isset(self::FIELD_ERRORS[$e->reason])) {
            return ValidationException::withMessages([self::FIELD_ERRORS[$e->reason] => [$detail]]);
        }

        $code = match ($e->reason) {
            'not_eligible', 'too_old' => ErrorCode::ReviewNotEligible,
            'duplicate' => ErrorCode::ReviewDuplicate,
            default => ErrorCode::RequestRefused,
        };

        return new ApiProblem($code, ['params' => ['reason' => $e->reason] + $e->params], detail: $detail);
    }
}
