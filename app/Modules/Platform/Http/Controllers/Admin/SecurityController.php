<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Contracts\AuditPeople;
use App\Modules\Platform\DTOs\PersonRef;
use App\Modules\Platform\Http\Requests\SecurityFeedRequest;
use App\Modules\Platform\Services\AuditFeed;
use App\Modules\Platform\Support\AuditCatalog;
use Illuminate\Contracts\View\View;

/**
 * Security & audit screens over the audit trail (read only): the activity log (what staff did, in plain words), security events (what deserves a look,
 * by how much) and login history (every sign-in, sign-out and failure). Behind audit.view; "my activity" is open to every staff member, for their own.
 * Addresses are shown masked, devices only as "browser on system".
 */
class SecurityController
{
    public function __construct(private readonly AuditFeed $feed, private readonly AuditPeople $people) {}

    public function activity(SecurityFeedRequest $request): View
    {
        $f = $request->filters();
        $person = $f['person'] === '' ? null : ($this->people->byIds([(int) $f['person']])[(int) $f['person']] ?? null);
        $query = [
            'actor_types' => ['staff'], 'except' => array_keys(AuditCatalog::SIGN_IN), 'from' => $f['from'], 'to' => $f['to'],
            'prefixes' => $f['area'] === '' ? [] : AuditCatalog::prefixesOf($f['area']),
        ];
        $person === null || $query['actor_id'] = $person->id;
        $page = $f['person'] !== '' && $person === null ? ['rows' => collect(), 'next' => null] : $this->feed->page($query, $request->before());

        return view('platform::admin.security.activity', ['filters' => $f, 'page' => $page, 'people' => $this->feed->people($page['rows']), 'staff' => $this->people->staff(), 'mine' => false]);
    }

    public function myActivity(SecurityFeedRequest $request): View
    {
        $f = $request->filters();
        $me = (int) $request->user('admin')?->getAuthIdentifier();
        $page = $this->feed->page(['actor_id' => $me, 'except' => array_keys(AuditCatalog::SIGN_IN), 'from' => $f['from'], 'to' => $f['to'], 'prefixes' => $f['area'] === '' ? [] : AuditCatalog::prefixesOf($f['area'])], $request->before());

        return view('platform::admin.security.activity', ['filters' => $f, 'page' => $page, 'people' => $this->feed->people($page['rows']), 'staff' => [], 'mine' => true]);
    }

    public function events(SecurityFeedRequest $request): View
    {
        $f = $request->filters();
        $actions = $f['severity'] === '' ? array_keys(AuditCatalog::SECURITY) : array_keys(array_filter(AuditCatalog::SECURITY, fn (string $s) => $s === $f['severity']));
        $page = $this->feed->page(['actions' => $actions, 'from' => $f['from'], 'to' => $f['to']], $request->before());

        return view('platform::admin.security.events', [
            'filters' => $f, 'page' => $page, 'people' => $this->feed->people($page['rows']),
            'counts' => $this->feed->counts(['auth.login_failed', 'auth.mfa_failed', 'auth.otp_failed', 'auth.account_locked', 'auth.recovery_code_used', 'auth.api_refresh_reuse']),
            'sources' => $this->feed->topSources(AuditCatalog::FAILURES),
        ]);
    }

    public function logins(SecurityFeedRequest $request): View
    {
        $f = $request->filters();
        $actions = $f['result'] === '' ? array_keys(AuditCatalog::SIGN_IN) : array_keys(array_filter(AuditCatalog::SIGN_IN, fn (string $r) => $r === $f['result']));
        $staff = $this->people->staff();
        $query = ['actions' => $actions, 'from' => $f['from'], 'to' => $f['to']];
        $unknownPerson = false;

        if ($f['person'] !== '') {   // one staff member
            $query['person'] = collect($staff)->first(fn (PersonRef $p) => $p->id === (int) $f['person']);
            $unknownPerson = $query['person'] === null;
        } elseif ($f['customer'] !== '') {   // one customer, by their exact email or phone
            $query['person'] = $this->people->customerByContact($f['customer']);
            $unknownPerson = $query['person'] === null;
        } elseif ($f['kind'] === 'staff') {
            $query['among'] = $staff;
        } elseif ($f['kind'] === 'customer') {
            $query['not_among'] = $staff;
        }

        $page = $unknownPerson ? ['rows' => collect(), 'next' => null] : $this->feed->page($query, $request->before());

        return view('platform::admin.security.logins', ['filters' => $f, 'page' => $page, 'people' => $this->feed->people($page['rows']), 'staff' => $staff, 'unknownPerson' => $unknownPerson]);
    }
}
