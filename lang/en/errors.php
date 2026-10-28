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
    ],
];
