<?php

declare(strict_types=1);

namespace App\Modules\IdentityAccess\Support;

use App\Modules\IdentityAccess\Models\User;
use App\Modules\Platform\Contracts\AuditPeople;
use App\Modules\Platform\DTOs\PersonRef;
use App\Modules\Platform\DTOs\PhoneNumber;
use Illuminate\Support\Facades\DB;

/** Names for the audit screens, from the accounts table. Names only: no email, phone or other personal data leaves this class. */
final class DatabaseAuditPeople implements AuditPeople
{
    private const COLUMNS = ['id', 'public_id', 'name', 'account_type'];

    public function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('users')->whereIn('id', $ids)->get(self::COLUMNS) as $u) {   // primary key
            $out[(int) $u->id] = $this->ref($u);
        }

        return $out;
    }

    public function byPublicIds(array $publicIds): array
    {
        $publicIds = array_values(array_unique(array_filter($publicIds)));
        if ($publicIds === []) {
            return [];
        }
        $out = [];
        foreach (DB::table('users')->whereIn('public_id', $publicIds)->get(self::COLUMNS) as $u) {   // unique public_id
            $out[(string) $u->public_id] = $this->ref($u);
        }

        return $out;
    }

    public function accountSubjectTypes(): array
    {
        // a model subject is stored with its morph class and public id; the app-session records use 'users' and the numeric id
        return [(new User)->getMorphClass(), 'users'];
    }

    public function staff(): array
    {
        return array_values(DB::table('users')->where('account_type', 'staff')->orderBy('name')->get(self::COLUMNS)->map(fn (\stdClass $u): PersonRef => $this->ref($u))->all());   // a handful of rows
    }

    public function customerByContact(string $emailOrPhone): ?PersonRef
    {
        $value = trim($emailOrPhone);
        $q = DB::table('users')->where('account_type', 'customer');
        if (str_contains($value, '@')) {
            $q->where('email', mb_strtolower($value));   // unique email
        } else {
            $phone = PhoneNumber::tryParse($value);
            if ($phone === null) {
                return null;
            }
            $q->where('phone', $phone->e164());   // unique phone
        }
        $u = $q->first(self::COLUMNS);

        return $u === null ? null : $this->ref($u);
    }

    private function ref(\stdClass $u): PersonRef
    {
        return new PersonRef((int) $u->id, (string) $u->public_id, (string) $u->name, $u->account_type === 'staff');
    }
}
