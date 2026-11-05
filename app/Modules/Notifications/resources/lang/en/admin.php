<?php

declare(strict_types=1);

return [
    'menu' => ['templates' => 'Messages', 'logs' => 'Message delivery'], 'templates_title' => 'Messages the shop sends', 'templates_help' => 'The words of every email and text message. Change them here; the messages themselves are chosen by the shop\'s software.',
    'logs_title' => 'Message delivery', 'logs_help' => 'Every message the shop tried to send, whether it arrived and why not. Phone numbers and email addresses are hidden, and the words of a message are not kept.',
    'message' => 'Message', 'channel' => 'Channel', 'kind' => 'Kind', 'state' => 'State', 'on' => 'On', 'off' => 'Off', 'none' => 'No messages have been set up yet.', 'none_logs' => 'No messages match.', 'any' => 'Any',
    'channels' => ['email' => 'Email', 'sms' => 'Text message'], 'kinds' => ['transactional' => 'About an order or account', 'marketing' => 'Marketing (needs a yes)'],
    'status' => ['queued' => 'Waiting', 'sending' => 'Sending', 'sent' => 'Sent', 'failed' => 'Failed, will retry', 'dead' => 'Gave up', 'skipped' => 'Not sent'],
    'skip' => ['no_address' => 'No usable address', 'no_consent' => 'The person has not said yes', 'template_off' => 'The message is switched off'],
    'health_bad' => ':dead message(s) gave up and :failing keep failing. Please look.', 'health_ok' => 'Nothing is failing. :queued waiting to be sent.', 'see_log' => 'See the delivery list',
    'back' => 'Back to messages', 'variables' => 'What you can use in the words', 'variables_help' => 'Write a variable like {{ name }}. Nothing else goes between the curly braces.', 'example' => 'example',
    'preview' => 'Preview', 'preview_button' => 'Preview', 'preview_note' => 'Filled in with example values. Nothing has been saved.', 'subject' => 'Subject', 'words' => 'Words', 'is_on' => 'This message is switched on',
    'save' => 'Save as a new version', 'saved' => 'Saved.', 'version' => 'version :n', 'no_words_yet' => 'no words yet (English is used)', 'history' => 'Earlier versions',
    'from' => 'From', 'to' => 'To', 'filter' => 'Filter', 'when' => 'When', 'to_whom' => 'To', 'tries' => 'Tries', 'why' => 'Why', 'reason' => 'Reason', 'retry' => 'Send again', 'retried' => 'The message is being sent again.',
    'erased' => 'The address was erased after the retention period; it cannot be sent again.',
];
