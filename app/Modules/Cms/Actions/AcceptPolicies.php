<?php

declare(strict_types=1);

namespace App\Modules\Cms\Actions;

use App\Modules\Cms\Contracts\PolicyAcceptances;
use App\Modules\Cms\Exceptions\PolicyException;

/** A signed-in customer accepts what the account page showed them. */
final class AcceptPolicies
{
    public function __construct(private readonly PolicyAcceptances $acceptances) {}

    /**
     * @param  list<int>  $shownReleaseIds
     *
     * @throws PolicyException
     */
    public function handle(int $userId, array $shownReleaseIds): void
    {
        $this->acceptances->assertShown($userId, 'signup', null, $shownReleaseIds);
        $this->acceptances->accept($userId, null, 'account', $shownReleaseIds);
    }
}
