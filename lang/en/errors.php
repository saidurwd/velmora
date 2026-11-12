<?php

declare(strict_types=1);

return [
    'error_code' => 'Error :code',
    'back_home' => 'Back to home page',
    '403' => ['title' => 'Access denied', 'message' => 'You do not have permission to view this page.'],
    '404' => ['title' => 'Page not found', 'message' => 'The page you are looking for does not exist or has moved.'],
    '419' => ['title' => 'Session expired', 'message' => 'Your session has expired. Please go back, refresh the page and try again.'],
    '429' => ['title' => 'Too many requests', 'message' => 'Please wait a moment and try again.'],
    '500' => ['title' => 'Something went wrong', 'message' => 'We have been notified and are looking into it. Please try again shortly.'],
    '503' => ['title' => 'Back soon', 'message' => 'We are doing some maintenance and will be back shortly.'],

    // API problem+json titles (stable codes live in ErrorCode)
    'api' => [
        'validation_failed' => 'The given data was invalid.',
        'unauthenticated' => 'Authentication is required.',
        'forbidden' => 'You are not allowed to do this.',
        'not_found' => 'The resource was not found.',
        'method_not_allowed' => 'This method is not allowed.',
        'rate_limited' => 'Too many requests.',
        'server_error' => 'An unexpected error occurred.',
        'invalid_credentials' => 'The details are not right. Please check them and try again.',
        'otp_invalid' => 'The code is not right or has expired. Please ask for a new one.',
        'refresh_invalid' => 'Your sign-in has ended. Please sign in again.',
        'refresh_reused' => 'For your safety this sign-in was ended. Please sign in again.',
        'product_not_found' => 'This product was not found or is not on sale.',
        'page_not_found' => 'This page was not found.',
        'otp_unavailable' => 'We could not send the code just now. Please try again in a moment.',
        'reauth_required' => 'Please confirm it is you before doing this.',
        'app_upgrade_required' => 'This version of the app is no longer supported. Please update the app.',
    ],
];
