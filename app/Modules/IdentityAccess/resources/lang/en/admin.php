<?php

declare(strict_types=1);

return [
    'login' => [
        'title' => 'Staff sign in',
        'heading' => 'Staff sign in',
        'email' => 'Work email',
        'password' => 'Password',
        'submit' => 'Continue',
    ],
    'mfa' => [
        'enrol_title' => 'Set up two-step verification',
        'enrol_intro' => 'Scan this QR code with an authenticator app (for example Google Authenticator, Microsoft Authenticator or Authy), then enter the 6-digit code it shows.',
        'qr_label' => 'QR code for your authenticator app',
        'cant_scan' => 'Can\'t scan the code?',
        'code' => '6-digit code',
        'enrol_submit' => 'Turn on and sign in',
        'challenge_title' => 'Two-step verification',
        'challenge_intro' => 'Enter the 6-digit code from your authenticator app, or one of your recovery codes.',
        'code_or_recovery' => 'Code or recovery code',
        'challenge_submit' => 'Verify and sign in',
        'invalid' => 'That code is not valid. Please try again.',
        'locked' => 'Too many incorrect codes. Please sign in again.',
        'recovery_used' => 'You signed in with a recovery code. Ask an administrator to reset your two-step verification if your device is lost.',
    ],
    'recovery' => [
        'title' => 'Save your recovery codes',
        'warning' => 'Each code works once if you lose your authenticator device. Store them somewhere safe. They will not be shown again.',
        'continue' => 'I have saved them — continue',
    ],
    'sessions' => [
        'title' => 'Your sessions',
        'device' => 'Device',
        'ip' => 'IP address',
        'last_seen' => 'Last active',
        'this_device' => 'this device',
        'revoke_others' => 'Sign out all other devices',
        'revoked' => ':count other session(s) signed out.',
    ],
];
