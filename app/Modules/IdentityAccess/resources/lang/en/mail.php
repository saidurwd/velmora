<?php

declare(strict_types=1);

return [
    'greeting' => 'Hello,',
    'ignore' => 'If you did not request this, you can safely ignore this email.',
    'verify' => [
        'subject' => 'Verify your email address for :app',
        'intro' => 'Thanks for creating an account. Please confirm your email address.',
        'action' => 'Verify email address',
        'expiry' => 'This link expires in :minutes minutes.',
    ],
    'reset' => [
        'subject' => 'Reset your :app password',
        'intro' => 'We received a request to reset your password.',
        'action' => 'Choose a new password',
        'expiry' => 'This link expires in :minutes minutes.',
    ],
    'exists' => [
        'subject' => 'You already have an account with :app',
        'intro' => 'Someone tried to create an account with this email address, but you already have one.',
        'login' => 'Sign in',
        'forgot' => 'Forgot your password? Reset it here: :url',
    ],
];
