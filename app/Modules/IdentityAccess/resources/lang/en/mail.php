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
    'invite' => [
        'subject' => 'You have been invited to :app',
        'intro' => ':by created a staff account for you on :app. Choose a password to get started.',
        'action' => 'Choose your password',
        'expiry' => 'This link expires in :minutes minutes.',
        'mfa' => 'On your first sign-in you will also set up two-step verification with an authenticator app.',
    ],
    'email_change' => [
        'subject' => 'Confirm your new email address for :app',
        'intro' => 'Please confirm that this is your new email address.',
        'action' => 'Confirm new email address',
        'expiry' => 'This link expires in :minutes minutes.',
    ],
    'changed' => [
        'subject_password' => 'Your :app password was changed',
        'body_password' => 'The password for your account was just changed, and all other devices were signed out.',
        'subject_email' => 'The email address on your :app account was changed',
        'body_email' => 'The email address on your account was just changed to a new one. This message was sent to your previous address.',
        'not_you' => 'If this was not you, reset your password immediately and contact support.',
    ],
];
