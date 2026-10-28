<?php

declare(strict_types=1);

return [
    'none' => 'not set',
    'confirm' => [
        'title' => 'Confirm it is you',
        'intro' => 'For your security, please confirm before changing sign-in details.',
        'submit' => 'Confirm',
        'by_code' => 'Or confirm with a code sent to your mobile',
        'send_code' => 'Send me a code',
        'code_sent' => 'If your mobile number is verified, we have sent a code.',
    ],
    'security' => ['title' => 'Sign-in & security'],
    'password' => [
        'title' => 'Change password',
        'set_title' => 'Set a password',
        'current' => 'Current password',
        'new' => 'New password',
        'submit' => 'Save password',
        'changed' => 'Your password was saved. Other devices have been signed out.',
    ],
    'email' => [
        'title' => 'Email address',
        'current' => 'Current: :email',
        'new' => 'New email address',
        'help' => 'We will send a confirmation link to the new address.',
        'submit' => 'Send confirmation link',
        'sent' => 'If that address can be used, we have sent it a confirmation link.',
        'changed' => 'Your email address was changed.',
        'invalid_link' => 'That confirmation link is invalid or no longer applies.',
    ],
    'phone' => [
        'title' => 'Mobile number',
        'current' => 'Current: :phone',
        'new' => 'New mobile number',
        'send' => 'Send code',
        'confirm' => 'Confirm number',
        'code_sent' => 'We sent a code to :phone.',
        'changed' => 'Your mobile number was changed.',
        'already_used' => 'This number is already used by another account.',
    ],
    'sessions' => [
        'title' => 'Signed-in devices',
        'count' => 'You are signed in on :count device(s).',
        'revoke_others' => 'Sign out all other devices',
        'revoked' => ':count other device(s) signed out.',
    ],
    'delete' => [
        'title' => 'Delete my account',
        'explain' => 'Ask us to delete your account. Orders and invoices we must keep by law are retained, with your personal details removed where the law allows.',
        'submit' => 'Request account deletion',
        'requested' => 'We received your request and will contact you to confirm.',
    ],
];
