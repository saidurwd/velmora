<?php

declare(strict_types=1);

namespace App\Modules\Platform\Contracts;

use App\Modules\Platform\DTOs\PersonRef;

/**
 * Who the people in the audit trail are. The trail stores a person as an id (actor) or as the account's public id (subject); this turns both into a name.
 * Defined here, in the kernel, so the audit screens need no other module; the module that owns accounts provides it (IdentityAccess).
 */
interface AuditPeople
{
    /**
     * @param  list<int>  $ids
     * @return array<int, PersonRef> keyed by id
     */
    public function byIds(array $ids): array;

    /**
     * @param  list<string>  $publicIds
     * @return array<string, PersonRef> keyed by public id
     */
    public function byPublicIds(array $publicIds): array;

    /**
     * The subject_type values the audit trail uses for a person's account (one per way of recording it).
     *
     * @return list<string>
     */
    public function accountSubjectTypes(): array;

    /**
     * Every staff member, by name, for the "person" filter and for telling staff from customers.
     *
     * @return list<PersonRef>
     */
    public function staff(): array;

    /** A customer found by their exact email address or phone number (never by part of one), or null. */
    public function customerByContact(string $emailOrPhone): ?PersonRef;
}
