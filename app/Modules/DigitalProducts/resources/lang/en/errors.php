<?php

declare(strict_types=1);

return [
    'variant_unknown' => 'That product was not found.',
    'variant_not_digital' => 'Only digital products have files.',
    'type_not_allowed' => 'This kind of file is not allowed. Allowed: :types.',
    'file_empty' => 'The file is empty or could not be read.',
    'file_too_large' => 'The file is bigger than :mb MB.',
    'content_mismatch' => 'The content of the file does not match its name. Nothing was stored.',
    'keys_not_utf8' => 'The file of keys must be saved as UTF-8 text.',
    'keys_file_too_large' => 'The file of keys is too large.',
    'keys_too_many' => 'A file may have at most :max keys. Split it and add the parts one at a time.',
    'policy_unknown' => 'That refund policy was not found.', 'policy_rule_invalid' => 'Choose one of the refund rules.', 'policy_days_invalid' => 'Enter a number of days from 1 to 3650.',
    'policy_words_required' => 'The policy needs a title and a text in English.', 'policy_words_incomplete' => 'The :locale title and text must both be filled in, or both empty.',
    'entitlement_not_found' => 'That download was not found.', 'entitlement_already_revoked' => 'This download has already been taken away.', 'reason_required' => 'Please give a reason.',
    'days_invalid' => 'Enter a number of days from 1 to :max.', 'downloads_invalid' => 'Enter a number of downloads from 1 to :max.', 'no_expiry_to_extend' => 'This download has no end date, so there is nothing to extend.', 'already_unlimited' => 'This download has no limit, so there is nothing to add.',
];
