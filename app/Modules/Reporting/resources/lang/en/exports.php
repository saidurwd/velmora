<?php

declare(strict_types=1);

return [
    'menu' => 'My report files',
    'title' => 'My report files',
    'intro' => 'Reports you asked for as spreadsheet files. A file is kept for :days days and only you can download it.',
    'ask' => 'Ask for a file',
    'ask_help' => 'A spreadsheet file (CSV) with every row of this report. You will find it under “My report files” when it is ready.',
    'requested' => 'We are preparing your file. It will appear here when it is ready.',
    'none' => 'You have not asked for any report files.',
    'report' => 'Report',
    'asked' => 'Asked on',
    'state' => 'State',
    'rows' => 'Rows',
    'file' => 'File',
    'download' => 'Download',
    'until' => 'Kept until :time',
    'gone' => 'Deleted',
    'link_note' => 'A download link works for :minutes minutes after you open this page, and only while you are signed in.',
    'status' => ['requested' => 'Waiting', 'running' => 'Being prepared', 'ready' => 'Ready', 'failed' => 'Could not be made', 'expired' => 'No longer available'],
    'errors' => [
        'unknown_report' => 'There is no such report.',
        'invalid_period' => 'Please give a real period.',
        'period_too_long' => 'Please choose at most :days days at a time.',
        'too_many_open' => 'You already have :max files waiting. Please wait until they are ready.',
        'too_many_rows' => 'This report has too many rows for one file. Please narrow it down.',
        'stale' => 'The file was not finished in time. Please ask again.',
        'failed' => 'The file could not be made. Please ask again.',
    ],
];
