<?php

declare(strict_types=1);

namespace App\Modules\Cms\Contracts;

use App\Modules\Cms\DTOs\PolicyNotice;

/**
 * Which policies a person must accept at sign-up and checkout, and the record that they did.
 * Context is 'signup' (also used by the account page) or 'checkout'. A policy nobody has released yet asks for nothing.
 */
interface PolicyAcceptances
{
    /**
     * The policies this person has not yet accepted in their current form. A guest ($userId null) is always asked.
     *
     * @return list<PolicyNotice>
     */
    public function outstanding(?int $userId, string $context, ?string $paymentMethod, string $locale): array;

    /**
     * Checks that the release ids a form carried are exactly what is outstanding now.
     *
     * @param  list<int>  $shownReleaseIds
     *
     * @throws \DomainException (a PolicyException: the message is policies_required or policies_changed)
     */
    public function assertShown(?int $userId, string $context, ?string $paymentMethod, array $shownReleaseIds): void;

    /**
     * Records acceptance of the CURRENT release of each policy named. Once per person (or order) and release; asking twice changes nothing.
     * A guest's acceptance belongs to the order, a customer's to the customer.
     *
     * @param  list<int>  $releaseIds
     *
     * @throws \DomainException (a PolicyException) when a release is no longer the current one
     */
    public function accept(?int $userId, ?int $orderId, string $channel, array $releaseIds): void;

    /** A guest who makes an account from their order keeps what they accepted at checkout. */
    public function carryOrderToUser(int $orderId, int $userId): void;
}
