<?php

declare(strict_types=1);

return [
    'menu' => ['activity' => 'Activity log', 'events' => 'Security events', 'logins' => 'Login history'],
    'filter' => 'Filter', 'clear' => 'Clear', 'older' => 'Older entries', 'details' => 'Details',
    'unknown_person' => 'Someone',
    'kind' => ['staff' => 'Staff', 'customer' => 'Customer', 'customers' => 'Customers'],
    'actor' => ['staff' => 'A staff member', 'customer' => 'A customer', 'guest' => 'A visitor', 'system' => 'The system'],
    'filters' => [
        'from' => 'From', 'to' => 'To', 'staff' => 'Staff member', 'everyone' => 'Everyone', 'any_staff' => 'Any', 'area' => 'Area', 'all_areas' => 'All areas',
        'severity' => 'Severity', 'any' => 'Any', 'result' => 'What happened', 'kind' => 'Who', 'customer' => 'Customer email or phone',
        'customer_help' => 'The full address or number: a part of one finds nothing.',
    ],
    'columns' => ['time' => 'When', 'severity' => 'Severity', 'event' => 'Event', 'account' => 'Account', 'ip' => 'Network address', 'person' => 'Person', 'what' => 'What happened', 'how' => 'How and where', 'device' => 'Device'],
    'severity' => ['high' => 'High', 'medium' => 'Medium', 'info' => 'Info'],
    'result' => ['success' => 'Signed in', 'failure' => 'Failed attempt', 'locked' => 'Blocked', 'signout' => 'Signed out'],
    'login_events' => [
        'auth.login' => 'Signed in', 'auth.api_login' => 'Signed in', 'auth.mfa_passed' => 'Two-step passed', 'auth.recovery_code_used' => 'Recovery code used',
        'auth.login_failed' => 'Wrong password', 'auth.mfa_failed' => 'Wrong two-step code', 'auth.otp_failed' => 'Wrong one-time code',
        'auth.account_locked' => 'Locked out', 'auth.api_refresh_reuse' => 'App sign-in stopped', 'auth.logout' => 'Signed out', 'auth.api_sessions_ended' => 'Signed out', 'auth.api_logout_all' => 'Signed out everywhere',
    ],
    'method' => ['password' => 'Password', 'phone_code' => 'Code by text message', 'two_step' => 'Two-step code', 'recovery' => 'Recovery code'],
    'where' => ['shop' => 'Shop website', 'staff' => 'Staff area', 'app' => 'Mobile app'],
    'device' => ['on' => ':browser on :os', 'app' => 'Mobile app', 'script' => 'Script or tool', 'unknown' => 'Unknown device'],
    'duration' => ['seconds' => ':n s', 'minutes' => ':n min', 'hours' => ':h h :m min', 'days' => ':n days'],

    'activity' => [
        'title' => 'Activity log', 'mine_title' => 'My activity',
        'intro' => 'What staff did, in plain words, newest first. Sign-ins are in the login history; the full technical record of each change is in the audit log.',
        'mine_intro' => 'What you did in the staff area, newest first.',
        'empty' => 'Nothing was done in this period.',
    ],
    'events' => [
        'title' => 'Security events', 'intro' => 'Signs of trouble and changes to who can do what. High: look at it today. Medium: worth knowing. Network addresses are shown in part only.',
        'last_day' => 'Last 24 hours', 'feed' => 'Events',
        'tiles' => ['failed_passwords' => 'Wrong passwords', 'failed_codes' => 'Wrong two-step codes', 'failed_otps' => 'Wrong one-time codes', 'lockouts' => 'Accounts locked', 'recovery_codes' => 'Recovery codes used', 'key_reuse' => 'App sign-ins stopped (old key used again)'],
        'sources' => 'Most failed attempts from', 'sources_help' => 'Network addresses with the most wrong passwords and codes in the last 24 hours. Many from one place can mean someone is guessing.',
        'no_sources' => 'No failed attempts in the last 24 hours.', 'failures' => ':count failed attempt|:count failed attempts',
        'empty' => 'No security events in this period.',
    ],
    'logins' => [
        'title' => 'Login history', 'intro' => 'Every sign-in, sign-out and failed attempt on the shop, the staff area and the app. A wrong password tried on an address with no account shows as “No such account”.',
        'unknown_account' => 'No such account', 'no_such_person' => 'No customer has that exact email address or phone number.', 'empty' => 'Nothing in this period.',
        'recent' => 'Recent sign-ins', 'recent_intro' => 'The last sign-ins and failed attempts on your account. Something you do not recognise? Change your password and sign out other devices.',
        'all' => 'See all sign-ins',
    ],
];
