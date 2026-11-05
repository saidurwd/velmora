<?php

declare(strict_types=1);

return [
    'template_unknown' => 'That message is not known.', 'locale_unknown' => 'That language is not switched on.', 'words_required' => 'Please write the words.', 'too_long' => 'The words are too long (at most :max characters).',
    'subject_required' => 'Please write a subject.', 'subject_invalid' => 'A subject is one line of at most 150 characters.', 'variable_unknown' => 'The words use {{ :name }}, which this message does not have. Use only the variables listed.',
    'braces_invalid' => 'There are curly braces that are not a variable. Write a variable as {{ name }} and nothing else between the braces.',
    'not_found' => 'That delivery was not found.', 'not_dead' => 'Only a message that gave up can be sent again, and only once.', 'payload_expired' => 'This message was kept for too long; its address has been erased, so it cannot be sent again.',
    'reason_required' => 'Please give a reason.',
];
