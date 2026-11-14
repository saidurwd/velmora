<?php

declare(strict_types=1);

return [
    'milliseconds' => ':n ms',
    'menu' => 'Operations', 'title' => 'Operations', 'intro' => 'How the system is running: health, scheduled tasks, failed background jobs and checks that the data adds up.',
    'menu_items' => ['health' => 'System health', 'queues' => 'Queue monitor', 'scheduler' => 'Scheduled tasks', 'failed' => 'Failed jobs', 'integrity' => 'Data checks', 'about' => 'About'],
    'queues' => [
        'title' => 'Queue monitor', 'blurb' => 'Background work waiting, running and failing.', 'refresh' => 'Refresh',
        'intro' => 'Background work: messages, files, report figures. Counts and kinds of job only; a job’s data is never shown.',
        'connection' => 'Queue connection', 'runner' => 'Who runs the queue', 'runners' => ['worker' => 'A queue worker', 'scheduler' => 'The scheduler, every minute', 'sync' => 'Straight away (no queue)'],
        'queues' => 'Queues', 'thresholds' => 'Amber when the oldest job has waited :warn, red at :critical.',
        'columns' => ['queue' => 'Queue', 'pending' => 'Waiting', 'delayed' => 'Later', 'running' => 'Running', 'oldest' => 'Oldest waiting', 'state' => 'State'],
        'states' => ['ok' => 'Flowing', 'warn' => 'Slow', 'critical' => 'Stuck', 'unknown' => 'Cannot tell'],
        'failed' => 'Failed in the last 24 hours', 'see_failed' => 'All failed jobs (:n)', 'no_failures' => 'Nothing failed in the last 24 hours.',
        'outbox' => 'Events to announce (outbox)', 'outbox_help' => 'Events saved with each change and announced to the rest of the system right after.', 'outbox_pending' => 'Waiting', 'outbox_failed' => 'Given up',
    ],
    'about' => [
        'title' => 'About', 'blurb' => 'This installation: release, software and settings in outline.', 'intro' => 'The :shop installation: what it runs on and what it is built from.',
        'release' => 'Release', 'development' => 'Development copy', 'built' => 'Built :when',
        'software' => 'Software', 'rows' => ['laravel' => 'Framework', 'php' => 'Language', 'database' => 'Database', 'time' => 'Time', 'languages' => 'Languages'],
        'time_value' => 'Stored in :stored, shown in :shown', 'language_names' => ['en' => 'English', 'bn' => 'Bangla'],
        'services' => 'Services in use', 'services_help' => 'Only the kind of each service: addresses, keys and passwords are never shown.',
        'service_names' => ['cache' => 'Cache', 'session' => 'Sessions', 'queue' => 'Queue', 'mail' => 'Email', 'media' => 'Product images', 'private' => 'Private files', 'sms' => 'Text messages', 'push' => 'App notifications', 'payments' => 'Online payments'],
        'payments' => ['live' => 'Live', 'sandbox' => 'Test (sandbox)'],
        'modules' => 'Modules (:n)', 'components' => 'Third-party components', 'components_help' => 'The open-source parts this shop asks for directly, with the installed version and licence.',
        'columns' => ['name' => 'Name', 'version' => 'Version', 'licence' => 'Licence', 'kind' => 'Kind'], 'kinds' => ['php' => 'Server (PHP)', 'js' => 'Browser (JavaScript)'],
    ],
    'health' => [
        'title' => 'System health', 'blurb' => 'Is everything up, and is work piling up?', 'checks' => 'Checks', 'work' => 'Work waiting', 'runtime' => 'This server',
        'probes' => ['database' => 'Database', 'cache' => 'Cache', 'storage' => 'File storage', 'queue' => 'Job queue'], 'scheduler' => 'Scheduler (last tick)',
        'ok' => 'OK', 'down' => 'Not working', 'never' => 'Never', 'unknown' => 'Unknown', 'seconds_ago' => ':seconds seconds ago', 'on' => 'On', 'off' => 'Off', 'open' => 'Open',
        'failed_jobs' => 'Failed jobs', 'outbox' => 'Messages waiting to be delivered', 'outbox_line' => ':pending waiting, :failed given up', 'oldest' => 'oldest', 'queue' => 'Queue “:name”',
        'rt' => ['environment' => 'Environment', 'php' => 'PHP', 'laravel' => 'Laravel', 'database' => 'Database version', 'queue' => 'Queue driver', 'debug' => 'Debug mode', 'maintenance' => 'Maintenance mode'],
        'warnings' => ['debug_on_in_production' => 'Debug mode is on in production. Turn it off: error pages can reveal secrets.', 'maintenance_on' => 'The shop is closed for maintenance.'],
    ],
    'scheduler' => [
        'title' => 'Scheduled tasks', 'blurb' => 'What runs by itself, and how it last went.', 'no_heartbeat' => 'The scheduler has not ticked yet.', 'heartbeat' => 'The scheduler last ticked :seconds seconds ago.',
        'stale' => 'That is too long: check that the scheduler is running.', 'note' => 'A task that hands work to the queue counts as done once handed over. If that work then fails, it shows under Failed jobs.',
        'columns' => ['task' => 'Task', 'schedule' => 'Schedule', 'next' => 'Next due', 'last' => 'Last started', 'result' => 'Result'], 'never' => 'Not yet',
        'statuses' => ['started' => 'Running or interrupted', 'ok' => 'Done', 'failed' => 'Failed', 'skipped' => 'Skipped'], 'late' => 'Late',
    ],
    'failed' => [
        'title' => 'Failed jobs', 'blurb' => 'Background work that did not finish.', 'intro' => 'Only the error’s first line is shown, never the job’s data. A job can be sent back only if it is safe to run again; others can be looked at and forgotten.',
        'horizon' => 'Queue dashboard (Horizon)', 'columns' => ['job' => 'Job', 'queue' => 'Queue', 'failed_at' => 'Failed', 'error' => 'Error', 'actions' => 'Actions'],
        'retry' => 'Send back', 'forget' => 'Forget', 'confirm_forget' => 'Forget this failed job? It cannot be sent back afterwards.', 'not_retryable' => 'Not safe to repeat',
        'empty' => 'No failed jobs.', 'older' => 'Older entries', 'retried' => 'The job was sent back to the queue.', 'forgotten' => 'The failed job was forgotten.',
    ],
    'integrity' => [
        'title' => 'Data checks', 'blurb' => 'Does the data add up?', 'intro' => 'Each check only reads the data and reports; nothing is ever changed or repaired here. They also run every night.',
        'run_all' => 'Run all checks', 'run_one' => 'Run this check', 'ran' => 'The checks ran.', 'not_run' => 'Not run yet', 'statuses' => ['ok' => 'All good', 'problem' => 'Needs a look', 'error' => 'Could not run'],
        'problems' => ':count found (shown up to a limit). Examples:',
    ],
    'checks' => [
        'stock_ledger' => 'Stock on hand matches the stock ledger', 'stock_reservations' => 'Stock held for orders matches the reservations', 'refunds_within_captured' => 'Refunds never exceed the money received',
        'order_lines_add_up' => 'Order lines add up to the order subtotal (recent orders)', 'coupon_use_counts' => 'Coupon use counts match the redemptions', 'outbox_not_stuck' => 'No delivery message is stuck or given up',
    ],
    'errors' => ['too_often' => 'You ran the checks several times in the last minute. Wait a minute, then try again.', 'job_gone' => 'That failed job was already handled by someone else.', 'job_not_retryable' => 'That kind of job is not safe to run again, so it cannot be sent back from here.', 'check_unknown' => 'That check does not exist.'],
];
